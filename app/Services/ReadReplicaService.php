<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

class ReadReplicaService
{
    protected DatabaseManager $db;
    protected array $replicaHealth = [];
    protected int $healthCheckInterval = 300; // 5 minutes
    
    // Replica configuration based on PRD requirements
    private const REPLICA_STRATEGY = [
        // High-volume reporting queries - use dedicated reporting replica
        'reporting' => [
            'primary_connection' => 'pgsql_reporting',
            'fallback_connection' => 'pgsql_read',
            'final_fallback' => 'pgsql',
            'lag_tolerance_seconds' => 30, // Acceptable for monthly reports
        ],
        // Real-time dashboard queries - use read replica with low lag tolerance
        'dashboard' => [
            'primary_connection' => 'pgsql_read',
            'fallback_connection' => 'pgsql',
            'final_fallback' => 'pgsql',
            'lag_tolerance_seconds' => 5, // Real-time requirements
        ],
        // Export operations - can tolerate higher lag for better performance
        'export' => [
            'primary_connection' => 'pgsql_reporting',
            'fallback_connection' => 'pgsql_read',
            'final_fallback' => 'pgsql',
            'lag_tolerance_seconds' => 60, // Batch operations can tolerate lag
        ],
        // Sync validation queries - must be current data
        'sync_validation' => [
            'primary_connection' => 'pgsql',
            'fallback_connection' => 'pgsql',
            'final_fallback' => 'pgsql',
            'lag_tolerance_seconds' => 0, // Must be current for data integrity
        ],
    ];

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Get optimal connection for reporting queries (PRD requirement: handle 119k+ hours efficiently).
     */
    public function getReportingConnection(array $context = []): Connection
    {
        $strategy = $context['type'] ?? 'reporting';
        $isLargeDataset = $context['large_dataset'] ?? false;
        $requiresCurrentData = $context['requires_current'] ?? false;
        
        // For large datasets (PRD: 119,033.02 hours), prefer reporting replica
        if ($isLargeDataset || $strategy === 'export') {
            return $this->getConnectionWithFallback('export', $context);
        }
        
        // For sync validation, ensure current data
        if ($requiresCurrentData || $strategy === 'sync_validation') {
            return $this->getConnectionWithFallback('sync_validation', $context);
        }
        
        // For dashboard queries, balance performance and freshness
        if ($strategy === 'dashboard') {
            return $this->getConnectionWithFallback('dashboard', $context);
        }
        
        return $this->getConnectionWithFallback('reporting', $context);
    }

    /**
     * Execute query with automatic replica selection for optimal performance.
     */
    public function executeWithReplicaSelection(string $query, array $bindings = [], array $context = []): \Illuminate\Support\Collection
    {
        $queryType = $this->analyzeQueryForReplica($query, $context);
        $connection = $this->getReportingConnection(['type' => $queryType, ...$context]);
        
        $startTime = microtime(true);
        
        try {
            Log::debug("Executing query on replica", [
                'connection' => $connection->getName(),
                'query_type' => $queryType,
                'query_preview' => substr($query, 0, 100)
            ]);
            
            $result = collect($connection->select($query, $bindings));
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logReplicaPerformance($connection->getName(), $queryType, $executionTime, $result->count());
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Replica query execution failed", [
                'connection' => $connection->getName(),
                'query_type' => $queryType,
                'error' => $e->getMessage(),
                'query_preview' => substr($query, 0, 100)
            ]);
            
            // Try fallback if not already on primary
            if ($connection->getName() !== 'pgsql') {
                Log::info("Retrying query on primary connection");
                $primaryConnection = $this->db->connection('pgsql');
                return collect($primaryConnection->select($query, $bindings));
            }
            
            throw $e;
        }
    }

    /**
     * Check replica lag and health status.
     */
    public function checkReplicaHealth(): array
    {
        $healthStatus = [];
        $replicas = ['pgsql_read', 'pgsql_reporting'];
        
        foreach ($replicas as $replicaName) {
            try {
                $replica = $this->db->connection($replicaName);
                $startTime = microtime(true);
                
                // Test basic connectivity
                $result = $replica->select('SELECT NOW() as current_time, pg_is_in_recovery() as is_replica');
                $responseTime = (microtime(true) - $startTime) * 1000;
                
                // Check if this is actually a replica
                $isReplica = $result[0]->is_replica ?? false;
                
                // Estimate lag (simplified - in production you'd use pg_stat_replication)
                $lagTime = $this->estimateReplicationLag($replica);
                
                $healthStatus[$replicaName] = [
                    'status' => 'healthy',
                    'response_time_ms' => round($responseTime, 2),
                    'is_replica' => $isReplica,
                    'estimated_lag_seconds' => $lagTime,
                    'last_check' => now(),
                    'suitable_for_reporting' => $lagTime <= 60, // PRD: acceptable for monthly reports
                    'suitable_for_dashboard' => $lagTime <= 5,  // Real-time requirements
                ];
                
            } catch (\Exception $e) {
                $healthStatus[$replicaName] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'last_check' => now(),
                    'suitable_for_reporting' => false,
                    'suitable_for_dashboard' => false,
                ];
            }
        }
        
        $this->replicaHealth = $healthStatus;
        return $healthStatus;
    }

    /**
     * Get replica routing recommendations for PRD performance targets.
     */
    public function getRoutingRecommendations(): array
    {
        $health = $this->checkReplicaHealth();
        $recommendations = [];
        
        // Analyze each use case from the PRD
        $useCases = [
            'monthly_reports' => [
                'description' => 'Monthly CSV reports with resource breakdown',
                'performance_target' => '< 30 minutes',
                'data_freshness' => 'Can tolerate 1-2 minutes lag',
            ],
            'real_time_progress' => [
                'description' => 'Live sync progress tracking',
                'performance_target' => '< 5 seconds update',
                'data_freshness' => 'Must be current',
            ],
            'validation_reports' => [
                'description' => 'Data validation (119,033.02 hours accuracy)',
                'performance_target' => '< 5 minutes',
                'data_freshness' => 'Must be current for integrity',
            ],
            'client_dashboards' => [
                'description' => 'Client project time consumption reports',
                'performance_target' => '< 10 seconds',
                'data_freshness' => 'Can tolerate 30 seconds lag',
            ],
        ];
        
        foreach ($useCases as $useCase => $requirements) {
            $bestConnection = $this->recommendConnectionForUseCase($useCase, $health);
            
            $recommendations[$useCase] = [
                'requirements' => $requirements,
                'recommended_connection' => $bestConnection['connection'],
                'reason' => $bestConnection['reason'],
                'estimated_performance' => $bestConnection['performance'],
                'meets_requirements' => $bestConnection['suitable'],
            ];
        }
        
        return $recommendations;
    }

    /**
     * Optimize replica usage for specific PRD scenarios.
     */
    public function optimizeForScenario(string $scenario, array $context = []): array
    {
        return match ($scenario) {
            'monthly_report_generation' => $this->optimizeForMonthlyReports($context),
            'real_time_sync_monitoring' => $this->optimizeForRealTimeSync($context),
            'large_dataset_export' => $this->optimizeForLargeExport($context),
            'client_dashboard_access' => $this->optimizeForClientDashboard($context),
            default => $this->getDefaultOptimization($context)
        };
    }

    /**
     * Get connection with intelligent fallback based on health and requirements.
     */
    private function getConnectionWithFallback(string $strategy, array $context = []): Connection
    {
        $config = self::REPLICA_STRATEGY[$strategy];
        $connections = [
            $config['primary_connection'],
            $config['fallback_connection'],
            $config['final_fallback']
        ];
        
        foreach ($connections as $connectionName) {
            try {
                $connection = $this->db->connection($connectionName);
                
                // Check if connection is healthy and meets lag requirements
                if ($this->isConnectionSuitable($connectionName, $config['lag_tolerance_seconds'])) {
                    Log::debug("Selected connection for {$strategy}", [
                        'connection' => $connectionName,
                        'strategy' => $strategy,
                        'was_primary_choice' => $connectionName === $config['primary_connection']
                    ]);
                    
                    return $connection;
                }
                
            } catch (\Exception $e) {
                Log::warning("Connection {$connectionName} unavailable, trying next", [
                    'error' => $e->getMessage(),
                    'strategy' => $strategy
                ]);
                continue;
            }
        }
        
        // All connections failed, return primary as last resort
        Log::error("All replica connections failed, using primary", ['strategy' => $strategy]);
        return $this->db->connection('pgsql');
    }

    /**
     * Analyze query to determine optimal replica routing.
     */
    private function analyzeQueryForReplica(string $query, array $context = []): string
    {
        $query = strtoupper(trim($query));
        
        // Large aggregation queries (PRD: handle 119k+ hours efficiently)
        if (str_contains($query, 'SUM(') && str_contains($query, 'GROUP BY')) {
            return 'export';
        }
        
        // Real-time monitoring queries
        if (str_contains($query, 'jira_sync_history') || 
            str_contains($query, 'sync_progress') ||
            $context['real_time'] ?? false) {
            return 'dashboard';
        }
        
        // Validation queries (PRD: ensure 100% data coverage)
        if (str_contains($query, 'COUNT(*)') && 
            (str_contains($query, 'total') || $context['validation'] ?? false)) {
            return 'sync_validation';
        }
        
        // Default to reporting for general queries
        return 'reporting';
    }

    /**
     * Check if connection is suitable for the given lag tolerance.
     */
    private function isConnectionSuitable(string $connectionName, int $lagToleranceSeconds): bool
    {
        // Always consider primary suitable
        if ($connectionName === 'pgsql') {
            return true;
        }
        
        // Check cached health if available
        $lastCheck = $this->replicaHealth[$connectionName]['last_check'] ?? null;
        $cacheAge = $lastCheck ? now()->diffInSeconds($lastCheck) : $this->healthCheckInterval + 1;
        
        // Refresh health check if cache is stale
        if ($cacheAge > $this->healthCheckInterval) {
            $this->checkReplicaHealth();
        }
        
        $health = $this->replicaHealth[$connectionName] ?? null;
        
        if (!$health || $health['status'] !== 'healthy') {
            return false;
        }
        
        $estimatedLag = $health['estimated_lag_seconds'] ?? 999;
        return $estimatedLag <= $lagToleranceSeconds;
    }

    /**
     * Estimate replication lag (simplified implementation).
     */
    private function estimateReplicationLag(Connection $replica): int
    {
        try {
            // In a real implementation, you'd query pg_stat_replication on the primary
            // For now, we'll simulate based on connection response time
            $startTime = microtime(true);
            $replica->select('SELECT 1');
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            // Rough estimation: higher response time suggests higher lag
            if ($responseTime > 100) {
                return 15; // Assume 15 seconds lag for slow responses
            } elseif ($responseTime > 50) {
                return 5;  // Assume 5 seconds lag for medium responses
            } else {
                return 1;  // Assume 1 second lag for fast responses
            }
            
        } catch (\Exception $e) {
            return 999; // High lag value to indicate issues
        }
    }

    /**
     * Recommend connection for specific use case.
     */
    private function recommendConnectionForUseCase(string $useCase, array $health): array
    {
        return match ($useCase) {
            'monthly_reports' => [
                'connection' => 'pgsql_reporting',
                'reason' => 'Optimized for large aggregation queries with acceptable lag',
                'performance' => 'Excellent for batch operations',
                'suitable' => $health['pgsql_reporting']['suitable_for_reporting'] ?? false,
            ],
            'real_time_progress' => [
                'connection' => 'pgsql',
                'reason' => 'Requires current data for sync progress accuracy',
                'performance' => 'Good, ensures data consistency',
                'suitable' => true,
            ],
            'validation_reports' => [
                'connection' => 'pgsql',
                'reason' => 'Must use primary for data integrity validation',
                'performance' => 'Good, ensures 100% accurate counts',
                'suitable' => true,
            ],
            'client_dashboards' => [
                'connection' => 'pgsql_read',
                'reason' => 'Balanced performance and freshness for user dashboards',
                'performance' => 'Very good for read-heavy operations',
                'suitable' => $health['pgsql_read']['suitable_for_dashboard'] ?? false,
            ],
            default => [
                'connection' => 'pgsql',
                'reason' => 'Default to primary for unknown use cases',
                'performance' => 'Good',
                'suitable' => true,
            ]
        };
    }

    /**
     * Optimize for monthly report generation (PRD: < 30 minutes).
     */
    private function optimizeForMonthlyReports(array $context = []): array
    {
        return [
            'recommended_connection' => 'pgsql_reporting',
            'optimizations' => [
                'use_persistent_connections' => true,
                'batch_size' => 5000,
                'enable_query_cache' => true,
                'parallel_processing' => false, // Use single connection for consistency
            ],
            'performance_estimate' => '15-25 minutes for typical monthly data',
            'notes' => 'Optimized for large dataset aggregation with acceptable lag tolerance'
        ];
    }

    /**
     * Optimize for real-time sync monitoring.
     */
    private function optimizeForRealTimeSync(array $context = []): array
    {
        return [
            'recommended_connection' => 'pgsql',
            'optimizations' => [
                'use_persistent_connections' => true,
                'query_timeout' => 5,
                'enable_query_cache' => false, // Need fresh data
                'polling_interval' => 2, // seconds
            ],
            'performance_estimate' => '< 1 second response time',
            'notes' => 'Uses primary connection to ensure real-time accuracy'
        ];
    }

    /**
     * Optimize for large dataset export (PRD: handle 119k+ hours).
     */
    private function optimizeForLargeExport(array $context = []): array
    {
        return [
            'recommended_connection' => 'pgsql_reporting',
            'optimizations' => [
                'use_persistent_connections' => true,
                'batch_size' => 10000,
                'streaming_export' => true,
                'enable_query_cache' => true,
                'connection_timeout' => 120,
            ],
            'performance_estimate' => '10-20 minutes for full dataset export',
            'notes' => 'Optimized for maximum throughput with dedicated reporting connection'
        ];
    }

    /**
     * Optimize for client dashboard access.
     */
    private function optimizeForClientDashboard(array $context = []): array
    {
        return [
            'recommended_connection' => 'pgsql_read',
            'optimizations' => [
                'use_persistent_connections' => true,
                'enable_query_cache' => true,
                'cache_ttl' => 300, // 5 minutes
                'batch_size' => 1000,
            ],
            'performance_estimate' => '2-5 seconds for typical dashboard queries',
            'notes' => 'Balanced approach for good user experience'
        ];
    }

    /**
     * Get default optimization strategy.
     */
    private function getDefaultOptimization(array $context = []): array
    {
        return [
            'recommended_connection' => 'pgsql_read',
            'optimizations' => [
                'use_persistent_connections' => true,
                'enable_query_cache' => true,
                'cache_ttl' => 600,
            ],
            'performance_estimate' => 'Varies by query complexity',
            'notes' => 'Standard read replica optimization'
        ];
    }

    /**
     * Log replica performance metrics.
     */
    private function logReplicaPerformance(string $connectionName, string $queryType, float $executionTime, int $resultCount): void
    {
        Log::info("Replica query performance", [
            'connection' => $connectionName,
            'query_type' => $queryType,
            'execution_time_ms' => round($executionTime, 2),
            'result_count' => $resultCount,
            'performance_tier' => $executionTime > 1000 ? 'slow' : ($executionTime > 100 ? 'medium' : 'fast')
        ]);
    }
}