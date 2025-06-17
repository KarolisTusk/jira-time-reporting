<?php

namespace App\Console\Commands;

use App\Services\DatabaseConnectionPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageDatabaseConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:connections:manage 
                            {action : Action to perform: stats, optimize, warmup, test}
                            {--connection= : Specific connection to test}
                            {--format=table : Output format: table, json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage database connection pools and monitor performance';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseConnectionPoolService $connectionPool): int
    {
        $action = $this->argument('action');
        
        return match ($action) {
            'stats' => $this->showConnectionStats($connectionPool),
            'optimize' => $this->optimizeConnections($connectionPool),
            'warmup' => $this->warmupConnections($connectionPool),
            'test' => $this->testConnections($connectionPool),
            default => $this->handleInvalidAction($action)
        };
    }
    
    /**
     * Show connection pool statistics.
     */
    private function showConnectionStats(DatabaseConnectionPoolService $connectionPool): int
    {
        $this->info('📊 Database Connection Pool Statistics');
        $this->newLine();
        
        try {
            $stats = $connectionPool->getConnectionPoolStats();
            
            if ($this->option('format') === 'json') {
                $this->line(json_encode($stats, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            
            // Connection Usage Table
            if (!empty($stats['connection_usage'])) {
                $this->info('Connection Usage:');
                $usageData = [];
                foreach ($stats['connection_usage'] as $connection => $usage) {
                    $usageData[] = [
                        'Connection' => $connection,
                        'Total Uses' => $usage['total_uses'],
                        'Read Ops' => $usage['read_operations'],
                        'Write Ops' => $usage['write_operations'],
                        'Report Ops' => $usage['reporting_operations'],
                        'Last Used' => $usage['last_used']?->diffForHumans() ?? 'Never',
                    ];
                }
                $this->table(['Connection', 'Total Uses', 'Read Ops', 'Write Ops', 'Report Ops', 'Last Used'], $usageData);
                $this->newLine();
            }
            
            // Active Connections Table
            if (!empty($stats['active_connections'])) {
                $this->info('Active Connections:');
                $activeData = [];
                foreach ($stats['active_connections'] as $connection => $info) {
                    $activeData[] = [
                        'Connection' => $connection,
                        'Status' => $info['status'],
                        'Pool Type' => $info['pool_type'] ?? 'N/A',
                        'Health' => $stats['pool_health'][$connection] ?? 'Unknown',
                    ];
                }
                $this->table(['Connection', 'Status', 'Pool Type', 'Health'], $activeData);
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to retrieve connection stats: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Optimize database connections.
     */
    private function optimizeConnections(DatabaseConnectionPoolService $connectionPool): int
    {
        $this->info('🔧 Optimizing database connections...');
        
        try {
            $results = $connectionPool->optimizeConnections();
            
            if (!empty($results['closed'])) {
                $this->info('✅ Closed idle connections: ' . implode(', ', $results['closed']));
            }
            
            if (!empty($results['failed_to_close'])) {
                $this->warn('⚠️ Failed to close some connections:');
                foreach ($results['failed_to_close'] as $failure) {
                    $this->line("  - {$failure['connection']}: {$failure['error']}");
                }
            }
            
            if (empty($results['closed']) && empty($results['failed_to_close'])) {
                $this->info('✅ All connections are already optimized');
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Connection optimization failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Warm up database connections.
     */
    private function warmupConnections(DatabaseConnectionPoolService $connectionPool): int
    {
        $this->info('🚀 Warming up database connections...');
        
        try {
            $results = $connectionPool->warmUpConnections();
            
            $this->table(
                ['Connection', 'Status'],
                collect($results)->map(fn($status, $connection) => [$connection, $status])->toArray()
            );
            
            $successful = collect($results)->filter(fn($status) => $status === 'warmed_up')->count();
            $total = count($results);
            
            $this->newLine();
            $this->info("✅ Warmed up {$successful}/{$total} connections successfully");
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Connection warmup failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Test database connections.
     */
    private function testConnections(DatabaseConnectionPoolService $connectionPool): int
    {
        $this->info('🧪 Testing database connections...');
        
        $connectionName = $this->option('connection');
        $connectionsToTest = $connectionName ? [$connectionName] : ['pgsql', 'pgsql_read', 'pgsql_reporting'];
        
        $results = [];
        
        foreach ($connectionsToTest as $connection) {
            $this->line("Testing connection: {$connection}");
            
            try {
                $startTime = microtime(true);
                
                // Test basic connection
                $db = DB::connection($connection);
                $result = $db->select('SELECT 1 as test, NOW() as current_time');
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                // Test more complex query
                $complexStart = microtime(true);
                $complexResult = $db->select('SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = ?', ['public']);
                $complexDuration = (microtime(true) - $complexStart) * 1000;
                
                $results[] = [
                    'Connection' => $connection,
                    'Status' => '✅ Healthy',
                    'Simple Query' => round($duration, 2) . 'ms',
                    'Complex Query' => round($complexDuration, 2) . 'ms',
                    'Tables Found' => $complexResult[0]->table_count ?? 'N/A',
                ];
                
            } catch (\Exception $e) {
                $results[] = [
                    'Connection' => $connection,
                    'Status' => '❌ Failed',
                    'Simple Query' => 'Error',
                    'Complex Query' => 'Error',
                    'Tables Found' => $e->getMessage(),
                ];
            }
        }
        
        $this->newLine();
        $this->table(['Connection', 'Status', 'Simple Query', 'Complex Query', 'Tables Found'], $results);
        
        $healthyCount = collect($results)->filter(fn($r) => str_contains($r['Status'], '✅'))->count();
        $totalCount = count($results);
        
        if ($healthyCount === $totalCount) {
            $this->info("✅ All {$totalCount} connections are healthy");
            return self::SUCCESS;
        } else {
            $this->warn("⚠️ {$healthyCount}/{$totalCount} connections are healthy");
            return self::FAILURE;
        }
    }
    
    /**
     * Handle invalid action.
     */
    private function handleInvalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->newLine();
        $this->info('Available actions:');
        $this->line('  stats    - Show connection pool statistics');
        $this->line('  optimize - Close idle connections and optimize pool');
        $this->line('  warmup   - Warm up all connection pools');
        $this->line('  test     - Test connection health');
        $this->newLine();
        $this->info('Examples:');
        $this->line('  php artisan db:connections:manage stats');
        $this->line('  php artisan db:connections:manage test --connection=pgsql_read');
        $this->line('  php artisan db:connections:manage stats --format=json');
        
        return self::FAILURE;
    }
}
