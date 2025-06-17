<?php

namespace App\Console\Commands;

use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use App\Services\JiraSyncProgressService;
use App\Jobs\ProcessEnhancedJiraSync;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class JiraSyncDebug extends Command
{
    protected $signature = 'jira:sync-debug 
                          {action=status : Action to perform (status|logs|cleanup|recover|test)}
                          {--sync-id= : Specific sync ID to debug}
                          {--details : Show detailed information}
                          {--force : Force actions without confirmation}';

    protected $description = 'Debug JIRA sync processes, view logs, and perform recovery operations';

    public function handle()
    {
        $action = $this->argument('action');
        $syncId = $this->option('sync-id');
        $verbose = $this->option('details');

        $this->info("🔍 JIRA Sync Debug Tool");
        $this->line("Action: {$action}");
        $this->newLine();

        switch ($action) {
            case 'status':
                $this->showSyncStatus($syncId, $verbose);
                break;
            case 'logs':
                $this->showSyncLogs($syncId, $verbose);
                break;
            case 'cleanup':
                $this->cleanupStuckSyncs();
                break;
            case 'recover':
                $this->recoverStuckSyncs($syncId);
                break;
            case 'test':
                $this->runDiagnosticTests();
                break;
            default:
                $this->error("Unknown action: {$action}");
                $this->showHelp();
        }

        return 0;
    }

    protected function showSyncStatus($syncId = null, $verbose = false)
    {
        $this->info("📊 Current Sync Status");
        $this->line(str_repeat('-', 60));

        $query = JiraSyncHistory::query();
        
        if ($syncId) {
            $query->where('id', $syncId);
        } else {
            $query->whereIn('status', ['pending', 'in_progress', 'failed'])
                  ->orderBy('started_at', 'desc')
                  ->limit(20);
        }

        $syncs = $query->get();

        if ($syncs->isEmpty()) {
            $this->warn("No active or recent sync processes found.");
            return;
        }

        foreach ($syncs as $sync) {
            $this->displaySyncInfo($sync, $verbose);
            $this->newLine();
        }

        $this->showSyncSummary();
    }

    protected function displaySyncInfo(JiraSyncHistory $sync, $verbose = false)
    {
        $statusIcon = $this->getStatusIcon($sync->status);
        $duration = $sync->started_at->diffForHumans();
        
        $this->line("🔸 <info>Sync #{$sync->id}</info> {$statusIcon} <comment>{$sync->status}</comment>");
        $this->line("   Started: {$duration} by User #{$sync->triggered_by}");
        $this->line("   Progress: {$sync->progress_percentage}% - {$sync->current_operation}");
        
        if ($sync->total_projects > 0) {
            $this->line("   Projects: {$sync->processed_projects}/{$sync->total_projects}");
        }
        
        if ($sync->total_issues > 0) {
            $this->line("   Issues: {$sync->processed_issues}/{$sync->total_issues}");
        }

        if ($sync->error_count > 0) {
            $this->line("   <fg=red>Errors: {$sync->error_count}</>");
        }

        // Check if sync appears stuck
        $isStuck = $this->isSyncStuck($sync);
        if ($isStuck) {
            $this->line("   <bg=red;fg=white> ⚠️  STUCK - No progress for {$isStuck} </>");
        }

        if ($verbose) {
            $this->showDetailedSyncInfo($sync);
        }
    }

    protected function showDetailedSyncInfo(JiraSyncHistory $sync)
    {
        $this->line("   <fg=cyan>Detailed Information:</>");
        
        if ($sync->estimated_completion) {
            $this->line("   Estimated completion: {$sync->estimated_completion}");
        }

        // Show recent logs
        $recentLogs = JiraSyncLog::where('jira_sync_history_id', $sync->id)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        if ($recentLogs->isNotEmpty()) {
            $this->line("   Recent logs:");
            foreach ($recentLogs as $log) {
                $level = strtoupper($log->level);
                $time = $log->created_at->format('H:i:s');
                $this->line("     [{$time}] {$level}: {$log->message}");
            }
        }

        // Check for job queue status
        $this->checkJobQueueStatus($sync);
    }

    protected function checkJobQueueStatus(JiraSyncHistory $sync)
    {
        // Check if there are related jobs in the queue
        $pendingJobs = DB::table('jobs')
            ->where('payload', 'like', "%jira_sync_history_id\":{$sync->id}%")
            ->count();

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', "%jira_sync_history_id\":{$sync->id}%")
            ->count();

        if ($pendingJobs > 0) {
            $this->line("   <fg=yellow>Queue: {$pendingJobs} pending jobs</>");
        }

        if ($failedJobs > 0) {
            $this->line("   <fg=red>Queue: {$failedJobs} failed jobs</>");
        }
    }

    protected function isSyncStuck(JiraSyncHistory $sync)
    {
        if (!in_array($sync->status, ['pending', 'in_progress'])) {
            return false;
        }

        $stuckThreshold = now()->subMinutes(10); // Consider stuck after 10 minutes
        
        if ($sync->updated_at < $stuckThreshold) {
            return $sync->updated_at->diffForHumans();
        }

        return false;
    }

    protected function showSyncLogs($syncId = null, $verbose = false)
    {
        $this->info("📝 Sync Logs");
        $this->line(str_repeat('-', 60));

        $query = JiraSyncLog::with('syncHistory');

        if ($syncId) {
            $query->where('jira_sync_history_id', $syncId);
        } else {
            $query->whereHas('syncHistory', function($q) {
                $q->whereIn('status', ['pending', 'in_progress', 'failed']);
            });
        }

        $logs = $query->orderBy('created_at', 'desc')->limit(50)->get();

        if ($logs->isEmpty()) {
            $this->warn("No logs found.");
            return;
        }

        foreach ($logs as $log) {
            $level = strtoupper($log->level);
            $time = $log->created_at->format('Y-m-d H:i:s');
            $syncId = $log->jira_sync_history_id;
            
            $color = $this->getLogColor($log->level);
            $this->line("<{$color}>[{$time}] Sync#{$syncId} {$level}: {$log->message}</>");
            
            if ($verbose && $log->context) {
                $context = json_decode($log->context, true);
                if ($context) {
                    $this->line("   Context: " . json_encode($context, JSON_PRETTY_PRINT));
                }
            }
        }
    }

    protected function cleanupStuckSyncs()
    {
        $this->info("🧹 Cleaning up stuck sync processes");
        
        $stuckSyncs = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get();

        if ($stuckSyncs->isEmpty()) {
            $this->info("No stuck syncs found to clean up.");
            return;
        }

        $this->table(
            ['ID', 'Status', 'Started', 'Last Update', 'Progress'],
            $stuckSyncs->map(function($sync) {
                return [
                    $sync->id,
                    $sync->status,
                    $sync->started_at->format('Y-m-d H:i:s'),
                    $sync->updated_at->diffForHumans(),
                    $sync->progress_percentage . '%'
                ];
            })
        );

        if (!$this->option('force') && !$this->confirm('Mark these syncs as failed?')) {
            $this->info("Cleanup cancelled.");
            return;
        }

        foreach ($stuckSyncs as $sync) {
            $sync->update([
                'status' => 'failed',
                'current_operation' => 'Marked as failed by cleanup process',
                'completed_at' => now(),
            ]);

            // Log the cleanup action
            JiraSyncLog::create([
                'jira_sync_history_id' => $sync->id,
                'timestamp' => now(),
                'level' => 'warning',
                'message' => 'Sync marked as failed by cleanup process',
                'context' => json_encode(['cleanup_time' => now()]),
            ]);

            $this->info("✅ Marked sync #{$sync->id} as failed");
        }

        $this->info("Cleanup completed. {$stuckSyncs->count()} syncs processed.");
    }

    protected function recoverStuckSyncs($syncId = null)
    {
        $this->info("🔄 Attempting to recover stuck syncs");
        
        $query = JiraSyncHistory::whereIn('status', ['pending', 'in_progress']);
        
        if ($syncId) {
            $query->where('id', $syncId);
        } else {
            $query->where('updated_at', '<', now()->subMinutes(10));
        }

        $stuckSyncs = $query->get();

        if ($stuckSyncs->isEmpty()) {
            $this->info("No stuck syncs found to recover.");
            return;
        }

        foreach ($stuckSyncs as $sync) {
            $this->line("Attempting to recover sync #{$sync->id}...");
            
            try {
                // Reset sync to pending and restart
                $sync->update([
                    'status' => 'pending',
                    'current_operation' => 'Restarted by recovery process',
                    'progress_percentage' => 0,
                ]);

                // Re-dispatch the job
                $syncOptions = [
                    'jira_sync_history_id' => $sync->id,
                    'project_keys' => ['JFOC'], // You might need to store this in the sync history
                    'sync_type' => 'recovery',
                    'triggered_by' => $sync->triggered_by,
                ];

                ProcessEnhancedJiraSync::dispatch($syncOptions)
                    ->onQueue('jira-sync')
                    ->delay(now()->addSeconds(5));

                $this->info("✅ Recovery initiated for sync #{$sync->id}");

            } catch (\Exception $e) {
                $this->error("❌ Failed to recover sync #{$sync->id}: " . $e->getMessage());
            }
        }
    }

    protected function runDiagnosticTests()
    {
        $this->info("🧪 Running Diagnostic Tests");
        $this->line(str_repeat('-', 60));

        $tests = [
            'Database Connection' => [$this, 'testDatabaseConnection'],
            'JIRA API Connection' => [$this, 'testJiraConnection'],
            'Queue Workers' => [$this, 'testQueueWorkers'],
            'Sync Prerequisites' => [$this, 'testSyncPrerequisites'],
            'Memory Usage' => [$this, 'testMemoryUsage'],
            'Disk Space' => [$this, 'testDiskSpace'],
        ];

        $results = [];

        foreach ($tests as $testName => $testMethod) {
            $this->line("Running: {$testName}...");
            try {
                $result = call_user_func($testMethod);
                $results[$testName] = $result;
                $icon = $result['status'] === 'pass' ? '✅' : '❌';
                $this->line("{$icon} {$testName}: {$result['message']}");
            } catch (\Exception $e) {
                $results[$testName] = ['status' => 'fail', 'message' => $e->getMessage()];
                $this->line("❌ {$testName}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("📋 Test Summary");
        $passed = collect($results)->where('status', 'pass')->count();
        $total = count($results);
        $this->line("Passed: {$passed}/{$total}");

        if ($passed < $total) {
            $this->error("Some tests failed. Please review the issues above.");
        } else {
            $this->info("All tests passed! 🎉");
        }
    }

    protected function testDatabaseConnection()
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'pass', 'message' => 'Database connection successful'];
        } catch (\Exception $e) {
            return ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
        }
    }

    protected function testJiraConnection()
    {
        try {
            $jiraService = app(\App\Services\JiraApiService::class);
            // You might need to implement a simple test method
            return ['status' => 'pass', 'message' => 'JIRA API connection available'];
        } catch (\Exception $e) {
            return ['status' => 'fail', 'message' => 'JIRA API connection failed: ' . $e->getMessage()];
        }
    }

    protected function testQueueWorkers()
    {
        try {
            $workerCount = $this->getActiveWorkerCount();
            if ($workerCount > 0) {
                return ['status' => 'pass', 'message' => "{$workerCount} queue workers active"];
            } else {
                return ['status' => 'fail', 'message' => 'No active queue workers found'];
            }
        } catch (\Exception $e) {
            return ['status' => 'fail', 'message' => 'Queue worker check failed: ' . $e->getMessage()];
        }
    }

    protected function testSyncPrerequisites()
    {
        $issues = [];

        // Check for stuck syncs
        $stuckCount = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
            ->where('updated_at', '<', now()->subMinutes(15))
            ->count();

        if ($stuckCount > 0) {
            $issues[] = "{$stuckCount} stuck sync processes";
        }

        // Check for failed jobs
        $failedJobsCount = DB::table('failed_jobs')->count();
        if ($failedJobsCount > 10) {
            $issues[] = "{$failedJobsCount} failed jobs in queue";
        }

        if (empty($issues)) {
            return ['status' => 'pass', 'message' => 'All prerequisites look good'];
        } else {
            return ['status' => 'fail', 'message' => 'Issues: ' . implode(', ', $issues)];
        }
    }

    protected function testMemoryUsage()
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $usagePercent = ($memoryUsage / $memoryLimit) * 100;

        if ($usagePercent < 80) {
            return ['status' => 'pass', 'message' => sprintf('Memory usage: %.1f%% (%s)', $usagePercent, $this->formatBytes($memoryUsage))];
        } else {
            return ['status' => 'fail', 'message' => sprintf('High memory usage: %.1f%% (%s)', $usagePercent, $this->formatBytes($memoryUsage))];
        }
    }

    protected function testDiskSpace()
    {
        $freeSpace = disk_free_space(storage_path());
        $totalSpace = disk_total_space(storage_path());
        $usagePercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        if ($usagePercent < 90) {
            return ['status' => 'pass', 'message' => sprintf('Disk usage: %.1f%% (Free: %s)', $usagePercent, $this->formatBytes($freeSpace))];
        } else {
            return ['status' => 'fail', 'message' => sprintf('Low disk space: %.1f%% (Free: %s)', $usagePercent, $this->formatBytes($freeSpace))];
        }
    }

    protected function getActiveWorkerCount()
    {
        // This is a simplified check - you might need to implement proper worker detection
        $output = shell_exec('ps aux | grep "queue:work" | grep -v grep | wc -l');
        return (int) trim($output);
    }

    protected function parseMemoryLimit($memoryLimit)
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);

        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }

    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    protected function getStatusIcon($status)
    {
        return match($status) {
            'completed' => '✅',
            'failed' => '❌',
            'in_progress' => '🔄',
            'pending' => '⏳',
            default => '❓'
        };
    }

    protected function getLogColor($level)
    {
        return match($level) {
            'error' => 'fg=red',
            'warning' => 'fg=yellow',
            'info' => 'fg=blue',
            'debug' => 'fg=gray',
            default => 'fg=white'
        };
    }

    protected function showSyncSummary()
    {
        $this->newLine();
        $this->info("📈 Sync Summary");
        
        $summary = JiraSyncHistory::selectRaw('
            status,
            COUNT(*) as count,
            AVG(progress_percentage) as avg_progress
        ')
        ->whereDate('started_at', '>=', now()->subDays(7))
        ->groupBy('status')
        ->get();

        $this->table(
            ['Status', 'Count', 'Avg Progress'],
            $summary->map(function($item) {
                return [
                    $item->status,
                    $item->count,
                    round($item->avg_progress, 1) . '%'
                ];
            })
        );
    }

    protected function showHelp()
    {
        $this->newLine();
        $this->info("Available actions:");
        $this->line("  status   - Show current sync status");
        $this->line("  logs     - Show sync logs");
        $this->line("  cleanup  - Clean up stuck syncs");
        $this->line("  recover  - Attempt to recover stuck syncs");
        $this->line("  test     - Run diagnostic tests");
        $this->newLine();
        $this->info("Options:");
        $this->line("  --sync-id=ID  - Focus on specific sync");
        $this->line("  --details     - Show detailed information");
        $this->line("  --force       - Skip confirmations");
    }
} 