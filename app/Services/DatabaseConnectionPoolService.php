<?php

namespace App\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DatabaseConnectionPoolService
{
    protected DatabaseManager $db;
    protected array $connectionStats = [];
    protected array $connectionPools = [];
    
    // Connection pool configurations
    private const POOL_CONFIG = [
        'read_operations' => [
            'connection' => 'pgsql_read',
            'max_connections' => 20,
            'timeout' => 30,
        ],
        'reporting_operations' => [
            'connection' => 'pgsql_reporting', 
            'max_connections' => 10,
            'timeout' => 60,
        ],
        'write_operations' => [
            'connection' => 'pgsql',
            'max_connections' => 5,
            'timeout' => 30,
        ],
    ];

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
        $this->initializeConnectionStats();
    }

    /**
     * Get optimized connection for read operations.
     */
    public function getReadConnection(array $context = []): \Illuminate\Database\Connection
    {
        $connectionName = $this->selectOptimalReadConnection($context);
        $connection = $this->getPooledConnection($connectionName, 'read_operations');
        
        $this->trackConnectionUsage($connectionName, 'read');
        
        return $connection;
    }

    /**
     * Get optimized connection for reporting operations.
     */
    public function getReportingConnection(array $context = []): \Illuminate\Database\Connection
    {
        $connectionName = $this->selectOptimalReportingConnection($context);
        $connection = $this->getPooledConnection($connectionName, 'reporting_operations');
        
        $this->trackConnectionUsage($connectionName, 'reporting');
        
        return $connection;
    }

    /**
     * Get connection for write operations.
     */
    public function getWriteConnection(): \Illuminate\Database\Connection
    {
        $connection = $this->getPooledConnection('pgsql', 'write_operations');
        
        $this->trackConnectionUsage('pgsql', 'write');
        
        return $connection;
    }

    /**
     * Execute a query with automatic connection selection.
     */
    public function executeOptimizedQuery(string $query, array $bindings = [], array $context = [])
    {
        $queryType = $this->analyzeQueryType($query);
        
        $connection = match ($queryType) {
            'read' => $this->getReadConnection($context),
            'reporting' => $this->getReportingConnection($context),
            'write' => $this->getWriteConnection(),
            default => $this->getReadConnection($context)
        };
        
        $startTime = microtime(true);
        
        try {
            $result = $connection->select($query, $bindings);
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logQueryPerformance($query, $executionTime, $queryType, $connection->getName());
            
            return $result;
        } catch (\Exception $e) {
            Log::error("Query execution failed", [
                'query' => substr($query, 0, 200),
                'connection' => $connection->getName(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Execute a callback with optimized connection.
     */
    public function withOptimizedConnection(string $operationType, callable $callback, array $context = [])
    {
        $connection = match ($operationType) {
            'read' => $this->getReadConnection($context),
            'reporting' => $this->getReportingConnection($context),
            'write' => $this->getWriteConnection(),
            default => $this->getReadConnection($context)
        };
        
        $originalConnection = DB::getDefaultConnection();
        
        try {
            DB::setDefaultConnection($connection->getName());
            return $callback($connection);
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
    }

    /**
     * Get connection pool statistics.
     */
    public function getConnectionPoolStats(): array
    {
        $stats = [
            'connection_usage' => $this->connectionStats,
            'active_connections' => [],
            'pool_health' => [],
        ];
        
        foreach (self::POOL_CONFIG as $poolName => $config) {
            $connectionName = $config['connection'];
            
            try {
                $connection = $this->db->connection($connectionName);
                $stats['active_connections'][$connectionName] = [
                    'status' => 'active',
                    'pool_type' => $poolName,
                    'last_used' => $this->connectionStats[$connectionName]['last_used'] ?? null,
                ];
                
                // Test connection health
                $connection->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
                $stats['pool_health'][$connectionName] = 'healthy';
                
            } catch (\Exception $e) {
                $stats['active_connections'][$connectionName] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $stats['pool_health'][$connectionName] = 'unhealthy';
            }
        }
        
        return $stats;
    }

    /**
     * Optimize connections by closing idle ones.
     */
    public function optimizeConnections(): array
    {
        $optimized = [];
        $now = now();
        
        foreach ($this->connectionStats as $connectionName => $stats) {
            $lastUsed = $stats['last_used'] ?? $now;
            $idleTime = $now->diffInMinutes($lastUsed);
            
            // Close connections idle for more than 30 minutes
            if ($idleTime > 30) {
                try {
                    $this->db->purge($connectionName);
                    $optimized['closed'][] = $connectionName;
                    unset($this->connectionStats[$connectionName]);
                } catch (\Exception $e) {
                    $optimized['failed_to_close'][] = [
                        'connection' => $connectionName,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
        Log::info("Connection pool optimization completed", $optimized);
        
        return $optimized;
    }

    /**
     * Warm up connection pools.
     */
    public function warmUpConnections(): array
    {
        $results = [];
        
        foreach (self::POOL_CONFIG as $poolName => $config) {
            $connectionName = $config['connection'];
            
            try {
                $connection = $this->db->connection($connectionName);
                
                // Test connection with a simple query
                $connection->select('SELECT 1 as test');
                
                $results[$connectionName] = 'warmed_up';
                
                Log::debug("Warmed up connection pool", [
                    'connection' => $connectionName,
                    'pool_type' => $poolName
                ]);
                
            } catch (\Exception $e) {
                $results[$connectionName] = 'failed: ' . $e->getMessage();
                
                Log::warning("Failed to warm up connection", [
                    'connection' => $connectionName,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Get pooled connection with management.
     */
    private function getPooledConnection(string $connectionName, string $poolType): \Illuminate\Database\Connection
    {
        $config = self::POOL_CONFIG[$poolType];
        
        try {
            $connection = $this->db->connection($connectionName);
            
            // Test connection health
            $connection->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
            
            return $connection;
            
        } catch (\Exception $e) {
            Log::warning("Connection pool issue, attempting reconnection", [
                'connection' => $connectionName,
                'pool_type' => $poolType,
                'error' => $e->getMessage()
            ]);
            
            // Purge and recreate connection
            $this->db->purge($connectionName);
            return $this->db->connection($connectionName);
        }
    }

    /**
     * Select optimal read connection based on context.
     */
    private function selectOptimalReadConnection(array $context): string
    {
        // Check if we have a read replica configured
        try {
            $this->db->connection('pgsql_read');
            return 'pgsql_read';
        } catch (\Exception $e) {
            // Fall back to main connection
            return 'pgsql';
        }
    }

    /**
     * Select optimal reporting connection based on context.
     */
    private function selectOptimalReportingConnection(array $context): string
    {
        $isComplexQuery = $context['complex'] ?? false;
        $expectedDuration = $context['expected_duration'] ?? 'short';
        
        // Use dedicated reporting connection for complex queries
        if ($isComplexQuery || $expectedDuration === 'long') {
            try {
                $this->db->connection('pgsql_reporting');
                return 'pgsql_reporting';
            } catch (\Exception $e) {
                // Fall back to read replica or main connection
                return $this->selectOptimalReadConnection($context);
            }
        }
        
        return $this->selectOptimalReadConnection($context);
    }

    /**
     * Analyze query type based on SQL.
     */
    private function analyzeQueryType(string $query): string
    {
        $query = strtoupper(trim($query));
        
        if (str_starts_with($query, 'SELECT')) {
            // Complex reporting queries
            if (str_contains($query, 'GROUP BY') || 
                str_contains($query, 'HAVING') || 
                str_contains($query, 'WINDOW') ||
                str_contains($query, 'WITH ')) {
                return 'reporting';
            }
            return 'read';
        }
        
        if (str_starts_with($query, 'INSERT') || 
            str_starts_with($query, 'UPDATE') || 
            str_starts_with($query, 'DELETE')) {
            return 'write';
        }
        
        return 'read';
    }

    /**
     * Track connection usage statistics.
     */
    private function trackConnectionUsage(string $connectionName, string $operationType): void
    {
        if (!isset($this->connectionStats[$connectionName])) {
            $this->connectionStats[$connectionName] = [
                'total_uses' => 0,
                'read_operations' => 0,
                'write_operations' => 0,
                'reporting_operations' => 0,
                'first_used' => now(),
                'last_used' => now(),
            ];
        }
        
        $this->connectionStats[$connectionName]['total_uses']++;
        $this->connectionStats[$connectionName]["{$operationType}_operations"]++;
        $this->connectionStats[$connectionName]['last_used'] = now();
    }

    /**
     * Log query performance metrics.
     */
    private function logQueryPerformance(string $query, float $executionTime, string $queryType, string $connectionName): void
    {
        if ($executionTime > 1000) { // Log slow queries (> 1 second)
            Log::warning("Slow query detected", [
                'execution_time_ms' => round($executionTime, 2),
                'query_type' => $queryType,
                'connection' => $connectionName,
                'query_preview' => substr($query, 0, 200),
            ]);
        } elseif ($executionTime > 100) { // Log medium queries (> 100ms)
            Log::info("Medium duration query", [
                'execution_time_ms' => round($executionTime, 2),
                'query_type' => $queryType,
                'connection' => $connectionName,
            ]);
        }
    }

    /**
     * Initialize connection statistics tracking.
     */
    private function initializeConnectionStats(): void
    {
        foreach (self::POOL_CONFIG as $poolConfig) {
            $connectionName = $poolConfig['connection'];
            $this->connectionStats[$connectionName] = [
                'total_uses' => 0,
                'read_operations' => 0,
                'write_operations' => 0,
                'reporting_operations' => 0,
                'first_used' => null,
                'last_used' => null,
            ];
        }
    }
}