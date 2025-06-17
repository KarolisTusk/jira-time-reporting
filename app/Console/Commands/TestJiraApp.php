<?php

namespace App\Console\Commands;

use App\Models\JiraSetting;
use App\Models\JiraProject;
use App\Models\JiraSyncHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestJiraApp extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jira:test-app {action=overview : Test action (overview|database|models|jobs)}';

    /**
     * The console command description.
     */
    protected $description = 'Test JIRA Reporter app functionality';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        $this->info("🧪 JIRA Reporter App Testing");
        $this->newLine();

        switch ($action) {
            case 'overview':
                return $this->showOverview();
                
            case 'database':
                return $this->testDatabase();
                
            case 'models':
                return $this->testModels();
                
            case 'jobs':
                return $this->testJobs();
                
            default:
                $this->error("Unknown action: {$action}");
                $this->info('Available actions: overview, database, models, jobs');
                return self::FAILURE;
        }
    }

    /**
     * Show application overview.
     */
    private function showOverview(): int
    {
        $this->info('📋 Application Overview');
        $this->newLine();

        // Check database connection
        try {
            DB::connection()->getPdo();
            $dbStatus = '🟢 Connected';
        } catch (\Exception $e) {
            $dbStatus = '🔴 Failed: ' . $e->getMessage();
        }

        // Check queue connection
        try {
            $queueStatus = config('queue.default') === 'database' ? '🟢 Database Queue' : '🟡 ' . config('queue.default');
        } catch (\Exception $e) {
            $queueStatus = '🔴 Failed';
        }

        // Check cache
        try {
            $cacheStatus = config('cache.default') === 'file' ? '🟢 File Cache' : '🟡 ' . config('cache.default');
        } catch (\Exception $e) {
            $cacheStatus = '🔴 Failed';
        }

        $this->table(
            ['Component', 'Status'],
            [
                ['Database', $dbStatus],
                ['Queue System', $queueStatus],
                ['Cache System', $cacheStatus],
                ['Laravel Version', '🟢 ' . app()->version()],
                ['Environment', '🟢 ' . app()->environment()],
            ]
        );

        $this->newLine();
        $this->info('🌐 Application URLs:');
        $this->line('• Main App: http://127.0.0.1:8001');
        $this->line('• Horizon Dashboard: http://127.0.0.1:8001/horizon (when using Redis)');
        
        $this->newLine();
        $this->info('🎯 Available Test Commands:');
        $this->line('• php artisan jira:test-app database   - Test database operations');
        $this->line('• php artisan jira:test-app models     - Test model functionality');
        $this->line('• php artisan jira:test-app jobs       - Test job system');

        return self::SUCCESS;
    }

    /**
     * Test database functionality.
     */
    private function testDatabase(): int
    {
        $this->info('🗄️ Database Testing');
        $this->newLine();

        try {
            // Test basic connection
            $this->info('Testing database connection...');
            DB::connection()->getPdo();
            $this->line('✅ Database connection successful');

            // Check tables
            $this->info('Checking JIRA tables...');
            $tables = [
                'jira_settings',
                'jira_projects', 
                'jira_issues',
                'jira_worklogs',
                'jira_sync_histories',
                'jira_sync_checkpoints',
                'jira_project_sync_statuses'
            ];

            $tableData = [];
            foreach ($tables as $table) {
                try {
                    $count = DB::table($table)->count();
                    $tableData[] = [$table, "✅ {$count} records"];
                } catch (\Exception $e) {
                    $tableData[] = [$table, "❌ Error: " . $e->getMessage()];
                }
            }

            $this->table(['Table', 'Status'], $tableData);

            // Test indexes
            $this->newLine();
            $this->info('Testing performance indexes...');
            
            try {
                $indexQuery = "SELECT indexname FROM pg_indexes WHERE tablename = 'jira_worklogs' AND indexname LIKE 'idx_%'";
                $indexes = DB::select($indexQuery);
                
                if (count($indexes) > 0) {
                    $this->line('✅ Performance indexes found: ' . count($indexes));
                    foreach ($indexes as $index) {
                        $this->line("  • {$index->indexname}");
                    }
                } else {
                    $this->line('⚠️ No performance indexes found');
                }
            } catch (\Exception $e) {
                $this->line('❌ Index check failed: ' . $e->getMessage());
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Database test failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Test model functionality.
     */
    private function testModels(): int
    {
        $this->info('🏗️ Model Testing');
        $this->newLine();

        $modelTests = [
            'JiraSetting' => JiraSetting::class,
            'JiraProject' => JiraProject::class,
            'JiraSyncHistory' => JiraSyncHistory::class,
        ];

        $results = [];
        
        foreach ($modelTests as $name => $class) {
            try {
                // Test model instantiation
                $model = new $class();
                $tableName = $model->getTable();
                
                // Test database query
                $count = $class::count();
                
                $results[] = [$name, $tableName, "✅ {$count} records"];
                
            } catch (\Exception $e) {
                $results[] = [$name, 'unknown', '❌ ' . $e->getMessage()];
            }
        }

        $this->table(['Model', 'Table', 'Status'], $results);

        // Test model relationships
        $this->newLine();
        $this->info('Testing model relationships...');
        
        try {
            // Test if we have any sync history
            $syncHistory = JiraSyncHistory::first();
            if ($syncHistory) {
                $this->line('✅ JiraSyncHistory model accessible');
                
                // Test relationships if available
                $checkpointsCount = $syncHistory->checkpoints()->count();
                $this->line("✅ Checkpoints relationship: {$checkpointsCount} checkpoints");
            } else {
                $this->line('ℹ️ No sync history records to test relationships');
            }
            
        } catch (\Exception $e) {
            $this->line('❌ Relationship test failed: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Test job system.
     */
    private function testJobs(): int
    {
        $this->info('⚡ Job System Testing');
        $this->newLine();

        try {
            // Check queue table
            $this->info('Checking queue system...');
            
            $queueConnection = config('queue.default');
            $this->line("Queue connection: {$queueConnection}");
            
            if ($queueConnection === 'database') {
                $pendingJobs = DB::table('jobs')->count();
                $failedJobs = DB::table('failed_jobs')->count();
                
                $this->table(
                    ['Queue Metric', 'Count'],
                    [
                        ['Pending Jobs', $pendingJobs],
                        ['Failed Jobs', $failedJobs],
                    ]
                );
            }

            // Test job classes
            $this->newLine();
            $this->info('Testing job classes...');
            
            $jobClasses = [
                'ProcessJiraManualSync' => 'App\\Jobs\\ProcessJiraManualSync',
                'ProcessJiraDailySync' => 'App\\Jobs\\ProcessJiraDailySync',
                'ProcessJiraRealTimeNotification' => 'App\\Jobs\\ProcessJiraRealTimeNotification',
                'ProcessJiraBackgroundTask' => 'App\\Jobs\\ProcessJiraBackgroundTask',
            ];

            $jobResults = [];
            foreach ($jobClasses as $name => $class) {
                if (class_exists($class)) {
                    $jobResults[] = [$name, '✅ Class exists'];
                } else {
                    $jobResults[] = [$name, '❌ Class not found'];
                }
            }

            $this->table(['Job Class', 'Status'], $jobResults);

            // Test job dispatch (without actually running)
            $this->newLine();
            $this->info('Testing job creation...');
            
            try {
                // Test creating a background task job
                $job = new \App\Jobs\ProcessJiraBackgroundTask('cache_cleanup', ['test' => true]);
                $this->line('✅ Job instantiation successful');
                
                $tags = $job->tags();
                $this->line('✅ Job tags: ' . implode(', ', $tags));
                
            } catch (\Exception $e) {
                $this->line('❌ Job creation failed: ' . $e->getMessage());
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Job system test failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}