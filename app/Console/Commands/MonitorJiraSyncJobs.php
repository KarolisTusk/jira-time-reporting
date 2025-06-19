<?php

namespace App\Console\Commands;

use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorJiraSyncJobs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jira:sync:monitor 
                            {action? : Action to perform (status|failed|retry|cleanup)}
                            {--hours=24 : Number of hours to look back for status}
                            {--limit=10 : Limit number of results}
                            {--retry-all : Retry all failed jobs}
                            {--cleanup-old : Clean up old completed sync records}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor JIRA sync jobs, view failed jobs, and manage sync history';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action') ?: 'status';

        return match ($action) {
            'status' => $this->showStatus(),
            'failed' => $this->showFailedSyncs(),
            'retry' => $this->retryFailedJobs(),
            'cleanup' => $this->cleanupOldRecords(),
            default => $this->showHelp(),
        };
    }

    /**
     * Show sync status overview.
     */
    protected function showStatus(): int
    {
        $hours = $this->option('hours');
        $since = now()->subHours($hours);

        $stats = JiraSyncHistory::where('started_at', '>=', $since)
            ->selectRaw('
                status,
                COUNT(*) as count,
                AVG(CASE WHEN completed_at IS NOT NULL THEN 
                    EXTRACT(EPOCH FROM (completed_at - started_at))
                    ELSE NULL END) as avg_duration_seconds
            ')
            ->groupBy('status')
            ->get();

        $this->info("JIRA Sync Status (Last {$hours} hours)");
        $this->line('----------------------------------------');

        if ($stats->isEmpty()) {
            $this->warn('No sync operations found in the specified time period.');

            return 0;
        }

        $table = [];
        $totalSyncs = 0;

        foreach ($stats as $stat) {
            $avgDuration = $stat->avg_duration_seconds ?
                gmdate('H:i:s', (int) $stat->avg_duration_seconds) : 'N/A';

            $table[] = [
                'Status' => ucfirst($stat->status),
                'Count' => $stat->count,
                'Avg Duration' => $avgDuration,
            ];
            $totalSyncs += $stat->count;
        }

        $this->table(['Status', 'Count', 'Avg Duration'], $table);
        $this->info("Total syncs: {$totalSyncs}");

        // Show recent syncs
        $this->line('');
        $this->info('Recent Sync Operations:');
        $recent = JiraSyncHistory::with('user')
            ->where('started_at', '>=', $since)
            ->orderBy('started_at', 'desc')
            ->limit($this->option('limit'))
            ->get();

        if ($recent->isNotEmpty()) {
            $recentTable = [];
            foreach ($recent as $sync) {
                $recentTable[] = [
                    'ID' => $sync->id,
                    'Status' => ucfirst($sync->status),
                    'Started' => $sync->started_at->format('Y-m-d H:i:s'),
                    'Duration' => $sync->formatted_duration ?: 'Running...',
                    'User' => $sync->user->email ?? 'Unknown',
                    'Progress' => $sync->progress_percentage.'%',
                    'Errors' => $sync->error_count,
                ];
            }
            $this->table([
                'ID', 'Status', 'Started', 'Duration', 'User', 'Progress', 'Errors',
            ], $recentTable);
        }

        return 0;
    }

    /**
     * Show failed sync operations.
     */
    protected function showFailedSyncs(): int
    {
        $failed = JiraSyncHistory::with('user')
            ->where('status', 'failed')
            ->orderBy('started_at', 'desc')
            ->limit($this->option('limit'))
            ->get();

        if ($failed->isEmpty()) {
            $this->info('No failed sync operations found.');

            return 0;
        }

        $this->error('Failed Sync Operations:');
        $this->line('-------------------------');

        foreach ($failed as $sync) {
            $this->line("ID: {$sync->id}");
            $this->line("Started: {$sync->started_at->format('Y-m-d H:i:s')}");
            $this->line('User: '.($sync->user->email ?? 'Unknown'));
            $this->line("Duration: {$sync->formatted_duration}");
            $this->line("Errors: {$sync->error_count}");

            if ($sync->error_details) {
                $this->line('Error Details: '.json_encode($sync->error_details, JSON_PRETTY_PRINT));
            }

            // Show recent error logs
            $errorLogs = $sync->logs()
                ->where('level', 'error')
                ->orderBy('timestamp', 'desc')
                ->limit(3)
                ->get();

            if ($errorLogs->isNotEmpty()) {
                $this->line('Recent Errors:');
                foreach ($errorLogs as $log) {
                    $this->line("  - {$log->timestamp->format('H:i:s')}: {$log->message}");
                }
            }

            $this->line('---');
        }

        if ($this->option('retry-all')) {
            return $this->retryFailedJobs();
        }

        return 0;
    }

    /**
     * Retry failed jobs.
     */
    protected function retryFailedJobs(): int
    {
        // Get failed queue jobs
        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'LIKE', '%ProcessJiraSync%')
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->info('No failed JIRA sync jobs found in the queue.');
        } else {
            if ($this->confirm("Found {$failedJobs->count()} failed JIRA sync jobs. Retry all?")) {
                $this->call('queue:retry', ['id' => 'all']);
                $this->info('All failed jobs have been retried.');
            }
        }

        // Also check for sync histories marked as failed that might need manual retry
        $failedSyncs = JiraSyncHistory::where('status', 'failed')
            ->where('started_at', '>=', now()->subDays(1))
            ->count();

        if ($failedSyncs > 0) {
            $this->warn("Found {$failedSyncs} failed sync operations in the last 24 hours.");
            $this->info('These may need manual investigation or re-triggering.');
        }

        return 0;
    }

    /**
     * Clean up old sync records.
     */
    protected function cleanupOldRecords(): int
    {
        $daysToKeep = 30; // Keep records for 30 days
        $cutoffDate = now()->subDays($daysToKeep);

        $oldSyncs = JiraSyncHistory::where('completed_at', '<', $cutoffDate)
            ->where('status', 'completed')
            ->count();

        if ($oldSyncs === 0) {
            $this->info('No old sync records to clean up.');

            return 0;
        }

        if ($this->confirm("Found {$oldSyncs} completed sync records older than {$daysToKeep} days. Delete them?")) {
            // Delete associated logs first
            $deletedLogs = JiraSyncLog::whereIn('jira_sync_history_id',
                JiraSyncHistory::where('completed_at', '<', $cutoffDate)
                    ->where('status', 'completed')
                    ->pluck('id')
            )->delete();

            // Delete sync histories
            $deletedSyncs = JiraSyncHistory::where('completed_at', '<', $cutoffDate)
                ->where('status', 'completed')
                ->delete();

            $this->info("Cleaned up {$deletedSyncs} sync records and {$deletedLogs} log entries.");
        }

        return 0;
    }

    /**
     * Show help information.
     */
    protected function showHelp(): int
    {
        $this->error('Invalid action specified.');
        $this->line('');
        $this->info('Available actions:');
        $this->line('  status   - Show sync status overview (default)');
        $this->line('  failed   - Show failed sync operations');
        $this->line('  retry    - Retry failed jobs');
        $this->line('  cleanup  - Clean up old completed sync records');
        $this->line('');
        $this->info('Examples:');
        $this->line('  php artisan jira:sync:monitor status --hours=12');
        $this->line('  php artisan jira:sync:monitor failed --limit=5');
        $this->line('  php artisan jira:sync:monitor retry --retry-all');
        $this->line('  php artisan jira:sync:monitor cleanup');

        return 1;
    }
}
