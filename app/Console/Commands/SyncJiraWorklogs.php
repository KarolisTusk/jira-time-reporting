<?php

namespace App\Console\Commands;

use App\Jobs\ProcessJiraWorklogIncrementalSync;
use App\Models\JiraProject;
use App\Models\JiraSetting;
use App\Models\JiraSyncHistory;
use App\Models\JiraWorklogSyncStatus;
use App\Services\JiraWorklogIncrementalSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncJiraWorklogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:sync-worklogs 
                            {--projects=* : Specific project keys to sync (optional)}
                            {--since= : Sync worklogs since this date (YYYY-MM-DD format)}
                            {--hours= : Sync worklogs from the last N hours (default: 24)}
                            {--dry-run : Show what would be synced without actually running}
                            {--status : Show worklog sync status for all projects}
                            {--force : Force sync all worklogs regardless of last sync time}
                            {--async : Run sync as background job instead of synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run incremental JIRA worklog synchronization for faster daily updates';

    /**
     * Execute the console command.
     */
    public function handle(JiraWorklogIncrementalSyncService $worklogSyncService): int
    {
        // Handle status display
        if ($this->option('status')) {
            return $this->showWorklogSyncStatus();
        }

        // Handle dry run
        if ($this->option('dry-run')) {
            return $this->showDryRun($worklogSyncService);
        }

        $this->info('🔄 Starting Incremental JIRA Worklog Sync...');

        try {
            // Validate JIRA configuration
            $jiraSetting = JiraSetting::first();
            if (!$jiraSetting) {
                $this->error('❌ JIRA settings not configured. Please configure JIRA settings first.');
                return self::FAILURE;
            }

            // Determine projects to sync
            $projectKeys = $this->getProjectsToSync();
            if (empty($projectKeys)) {
                $this->warn('⚠️ No projects found to sync.');
                return self::SUCCESS;
            }

            // Determine since date
            $sinceDate = $this->getSinceDate();

            $this->info("📊 Syncing worklogs for projects: " . implode(', ', $projectKeys));
            if ($sinceDate) {
                $this->info("📅 Since: " . $sinceDate->format('Y-m-d H:i:s'));
            } else {
                $this->info("📅 Since: All time (first sync)");
            }

            // Check if we should run as background job
            if ($this->option('async')) {
                return $this->dispatchAsyncJob($projectKeys, $sinceDate);
            }

            // Run sync synchronously with progress display
            return $this->runSyncWithProgress($worklogSyncService, $projectKeys, $sinceDate);

        } catch (\Exception $e) {
            $this->error('❌ Worklog sync failed: ' . $e->getMessage());
            Log::error('Worklog sync command failed', [
                'exception' => $e->getMessage(),
                'options' => $this->options(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Show worklog sync status for all projects.
     */
    protected function showWorklogSyncStatus(): int
    {
        $this->info('📊 JIRA Worklog Sync Status');
        $this->line('');

        $statuses = JiraWorklogSyncStatus::orderBy('last_sync_at', 'desc')->get();

        if ($statuses->isEmpty()) {
            $this->warn('No worklog sync history found.');
            return self::SUCCESS;
        }

        $headers = ['Project', 'Last Sync', 'Status', 'Processed', 'Added', 'Updated', 'Errors'];
        $rows = [];

        foreach ($statuses as $status) {
            $rows[] = [
                $status->project_key,
                $status->last_sync_at ? $status->last_sync_at->format('Y-m-d H:i:s') : 'Never',
                $this->formatStatus($status->last_sync_status),
                number_format($status->worklogs_processed),
                number_format($status->worklogs_added),
                number_format($status->worklogs_updated),
                $status->last_error ? '⚠️ Yes' : '✅ No',
            ];
        }

        $this->table($headers, $rows);

        // Show summary stats
        $stats = [
            'Total Projects' => $statuses->count(),
            'Recently Synced (24h)' => $statuses->where('last_sync_at', '>=', now()->subDay())->count(),
            'Total Worklogs Processed' => number_format($statuses->sum('worklogs_processed')),
            'Projects with Errors' => $statuses->whereNotNull('last_error')->count(),
        ];

        $this->line('');
        $this->info('📈 Summary Statistics:');
        foreach ($stats as $label => $value) {
            $this->line("  {$label}: {$value}");
        }

        return self::SUCCESS;
    }

    /**
     * Show what would be synced without actually running.
     */
    protected function showDryRun(JiraWorklogIncrementalSyncService $worklogSyncService): int
    {
        $this->info('🔍 Dry Run - Worklog Sync Preview');
        $this->line('');

        $projectKeys = $this->getProjectsToSync();
        $sinceDate = $this->getSinceDate();

        foreach ($projectKeys as $projectKey) {
            $status = JiraWorklogSyncStatus::where('project_key', $projectKey)->first();
            
            $this->line("📁 Project: {$projectKey}");
            $this->line("  Last Sync: " . ($status?->last_sync_at ? $status->last_sync_at->format('Y-m-d H:i:s') : 'Never'));
            $this->line("  Since Date: " . ($sinceDate ? $sinceDate->format('Y-m-d H:i:s') : 'All time'));
            $this->line("  Status: " . ($status ? $this->formatStatus($status->last_sync_status) : 'Not synced'));
            $this->line('');
        }

        $this->info('💡 Add --async flag to run as background job');
        $this->info('💡 Remove --dry-run flag to execute the sync');

        return self::SUCCESS;
    }

    /**
     * Get projects to sync based on command options.
     */
    protected function getProjectsToSync(): array
    {
        $specifiedProjects = $this->option('projects');
        
        if (!empty($specifiedProjects)) {
            return $specifiedProjects;
        }

        // Get all available projects
        return JiraProject::pluck('project_key')->toArray();
    }

    /**
     * Get the since date based on command options.
     */
    protected function getSinceDate(): ?Carbon
    {
        // Explicit since date
        if ($this->option('since')) {
            return Carbon::parse($this->option('since'));
        }

        // Force flag ignores last sync time
        if ($this->option('force')) {
            return null;
        }

        // Hours option
        if ($this->option('hours')) {
            return now()->subHours((int) $this->option('hours'));
        }

        // Default: 24 hours ago
        return now()->subHours(24);
    }

    /**
     * Dispatch worklog sync as background job.
     */
    protected function dispatchAsyncJob(array $projectKeys, ?Carbon $sinceDate): int
    {
        // Create sync history record
        $syncHistory = JiraSyncHistory::create([
            'status' => 'pending',
            'sync_type' => 'worklog_incremental',
            'project_keys' => $projectKeys,
            'start_time' => now(),
            'metadata' => [
                'since_date' => $sinceDate?->toISOString(),
                'triggered_by' => 'console_command',
                'options' => $this->options(),
            ],
        ]);

        // Dispatch job
        ProcessJiraWorklogIncrementalSync::dispatch([
            'project_keys' => $projectKeys,
            'since_date' => $sinceDate?->toISOString(),
            'manual' => true,
        ], $syncHistory->id);

        $this->info("✅ Worklog sync job dispatched successfully!");
        $this->info("📝 Sync History ID: {$syncHistory->id}");
        $this->info("🔍 Monitor progress in the admin panel or with: php artisan jira:sync-debug status");

        return self::SUCCESS;
    }

    /**
     * Run sync synchronously with progress display.
     */
    protected function runSyncWithProgress(
        JiraWorklogIncrementalSyncService $worklogSyncService,
        array $projectKeys,
        ?Carbon $sinceDate
    ): int {
        $progressBar = $this->output->createProgressBar(count($projectKeys));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Starting...');

        $totalResults = [
            'worklogs_processed' => 0,
            'worklogs_added' => 0,
            'worklogs_updated' => 0,
            'worklogs_skipped' => 0,
            'errors' => [],
        ];

        foreach ($projectKeys as $projectKey) {
            $progressBar->setMessage("Syncing {$projectKey}...");
            
            $results = $worklogSyncService->syncWorklogsIncremental(
                [$projectKey],
                $sinceDate
            );

            $totalResults['worklogs_processed'] += $results['worklogs_processed'];
            $totalResults['worklogs_added'] += $results['worklogs_added'];
            $totalResults['worklogs_updated'] += $results['worklogs_updated'];
            $totalResults['worklogs_skipped'] += $results['worklogs_skipped'];
            $totalResults['errors'] = array_merge($totalResults['errors'], $results['errors']);

            $progressBar->advance();
        }

        $progressBar->setMessage('Completed!');
        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Display results
        $this->info('✅ Worklog sync completed!');
        $this->line('');
        $this->info('📊 Results:');
        $this->line("  Worklogs Processed: " . number_format($totalResults['worklogs_processed']));
        $this->line("  Worklogs Added: " . number_format($totalResults['worklogs_added']));
        $this->line("  Worklogs Updated: " . number_format($totalResults['worklogs_updated']));
        $this->line("  Worklogs Skipped: " . number_format($totalResults['worklogs_skipped']));

        if (!empty($totalResults['errors'])) {
            $this->line('');
            $this->warn('⚠️ Errors encountered:');
            foreach (array_slice($totalResults['errors'], 0, 5) as $error) {
                $this->line("  • {$error}");
            }
            if (count($totalResults['errors']) > 5) {
                $this->line("  • ... and " . (count($totalResults['errors']) - 5) . " more errors");
            }
        }

        return empty($totalResults['errors']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Format sync status for display.
     */
    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'completed' => '✅ Completed',
            'completed_with_errors' => '⚠️ Completed with errors',
            'in_progress' => '🔄 In Progress',
            'failed' => '❌ Failed',
            'pending' => '⏳ Pending',
            default => "❓ {$status}",
        };
    }
}
