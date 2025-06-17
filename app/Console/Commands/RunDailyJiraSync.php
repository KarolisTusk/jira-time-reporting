<?php

namespace App\Console\Commands;

use App\Jobs\AutomatedDailyJiraSync;
use App\Models\JiraProjectSyncStatus;
use App\Models\JiraSetting;
use App\Models\JiraSyncHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunDailyJiraSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:daily-sync 
                            {--projects=* : Specific project keys to sync (optional)}
                            {--force : Force sync all projects regardless of last sync time}
                            {--dry-run : Show what would be synced without actually running}
                            {--status : Show current sync status for all projects}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run daily automated JIRA synchronization for all configured projects';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle status display
        if ($this->option('status')) {
            return $this->showSyncStatus();
        }

        // Handle dry run
        if ($this->option('dry-run')) {
            return $this->showDryRun();
        }

        $this->info('🚀 Starting Daily JIRA Sync...');
        
        try {
            // Validate JIRA configuration
            $this->validateConfiguration();
            
            // Check if daily sync is already running
            if (AutomatedDailyJiraSync::isDailySyncRunning()) {
                $this->warn('⚠️  Daily sync is already running. Skipping...');
                return Command::FAILURE;
            }

            // Get options
            $projectKeys = $this->option('projects') ?: null;
            $forceSync = $this->option('force');

            // Show what will be synced
            $this->displaySyncPlan($projectKeys, $forceSync);

            // Dispatch the daily sync job
            AutomatedDailyJiraSync::dispatch($projectKeys, $forceSync);
            
            $this->info('✅ Daily sync job dispatched successfully');
            $this->line('📊 Monitor progress with: php artisan jira:daily-sync --status');
            
            Log::info('Daily JIRA sync command executed', [
                'project_keys' => $projectKeys,
                'force_sync' => $forceSync,
                'triggered_by' => 'artisan_command',
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to start daily sync: ' . $e->getMessage());
            Log::error('Daily JIRA sync command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Validate JIRA configuration.
     */
    protected function validateConfiguration(): void
    {
        $settings = JiraSetting::first();
        
        if (!$settings) {
            throw new \Exception('JIRA settings not configured. Please configure JIRA settings first.');
        }

        if (empty($settings->project_keys)) {
            throw new \Exception('No project keys configured in JIRA settings.');
        }

        if (!$settings->jira_host || !$settings->api_token) {
            throw new \Exception('JIRA connection settings incomplete (missing host or API token).');
        }

        $this->line("📡 JIRA Host: {$settings->jira_host}");
        $this->line("📋 Configured Projects: " . implode(', ', $settings->project_keys));
    }

    /**
     * Display what will be synced.
     */
    protected function displaySyncPlan(?array $projectKeys, bool $forceSync): void
    {
        $settings = JiraSetting::first();
        $allProjects = $settings->project_keys ?? [];
        
        $projectsToCheck = $projectKeys ?: $allProjects;
        $projectsNeedingSync = [];
        
        $this->line('');
        $this->info('📋 Sync Plan:');
        
        foreach ($projectsToCheck as $projectKey) {
            $status = JiraProjectSyncStatus::where('project_key', $projectKey)->first();
            
            if ($forceSync) {
                $projectsNeedingSync[] = $projectKey;
                $this->line("  🔄 {$projectKey} - FORCE SYNC");
            } elseif (!$status) {
                $projectsNeedingSync[] = $projectKey;
                $this->line("  🆕 {$projectKey} - Initial sync needed");
            } elseif ($status->isDueForSync(24)) {
                $projectsNeedingSync[] = $projectKey;
                $lastSync = $status->last_sync_at ? $status->last_sync_at->diffForHumans() : 'Never';
                $this->line("  🔄 {$projectKey} - Due for sync (Last: {$lastSync})");
            } else {
                $lastSync = $status->last_sync_at->diffForHumans();
                $this->line("  ✅ {$projectKey} - Up to date (Last: {$lastSync})");
            }
        }
        
        if (empty($projectsNeedingSync)) {
            $this->info('ℹ️  No projects need syncing at this time.');
        } else {
            $this->info("🎯 Will sync " . count($projectsNeedingSync) . " project(s): " . implode(', ', $projectsNeedingSync));
        }
    }

    /**
     * Show dry run information.
     */
    protected function showDryRun(): int
    {
        $this->info('🔍 Dry Run - Daily JIRA Sync');
        
        try {
            $this->validateConfiguration();
            
            $projectKeys = $this->option('projects') ?: null;
            $forceSync = $this->option('force');
            
            $this->displaySyncPlan($projectKeys, $forceSync);
            
            $this->line('');
            $this->info('ℹ️  This was a dry run. No actual sync was performed.');
            $this->line('   Run without --dry-run to execute the sync.');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Configuration error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Show current sync status.
     */
    protected function showSyncStatus(): int
    {
        $this->info('📊 JIRA Sync Status Dashboard');
        $this->line('');

        // Show last daily sync summary
        $lastDailySync = AutomatedDailyJiraSync::getLastDailySyncSummary();
        if ($lastDailySync) {
            $this->info('🕒 Last Daily Sync:');
            $this->line("   Status: {$lastDailySync['status']}");
            $this->line("   Started: {$lastDailySync['started_at']}");
            $this->line("   Duration: {$lastDailySync['duration']}");
            $this->line("   Projects: {$lastDailySync['projects_processed']}/{$lastDailySync['total_projects']}");
            $this->line("   Issues: {$lastDailySync['issues_processed']}");
            $this->line("   Worklogs: {$lastDailySync['worklogs_imported']}");
            $this->line("   Success Rate: {$lastDailySync['success_rate']}%");
            $this->line('');
        }

        // Show current running sync
        if (AutomatedDailyJiraSync::isDailySyncRunning()) {
            $this->warn('⚠️  Daily sync is currently running');
            
            $runningSyncs = JiraSyncHistory::where('sync_type', 'automated_daily')
                ->where('status', 'in_progress')
                ->where('started_at', '>=', now()->subHours(4))
                ->get();
                
            foreach ($runningSyncs as $sync) {
                $this->line("   Sync ID: {$sync->id}");
                $this->line("   Progress: {$sync->progress_percentage}%");
                $this->line("   Current: {$sync->current_operation}");
                $this->line("   Runtime: {$sync->started_at->diffForHumans()}");
            }
            $this->line('');
        }

        // Show project sync statuses
        $this->info('📋 Project Sync Status:');
        $statuses = JiraProjectSyncStatus::orderBy('project_key')->get();
        
        if ($statuses->isEmpty()) {
            $this->line('   No project sync statuses found.');
        } else {
            foreach ($statuses as $status) {
                $lastSync = $status->last_sync_at ? $status->last_sync_at->diffForHumans() : 'Never';
                $statusIcon = match($status->last_sync_status) {
                    'completed' => '✅',
                    'failed' => '❌',
                    'in_progress' => '🔄',
                    default => '⏳'
                };
                
                $this->line("   {$statusIcon} {$status->project_key}: {$status->last_sync_status} (Last: {$lastSync})");
                
                if ($status->last_sync_status === 'failed' && $status->last_error) {
                    $this->line("      Error: " . substr($status->last_error, 0, 80) . '...');
                }
            }
        }

        // Show overall statistics
        $this->line('');
        $summary = JiraProjectSyncStatus::getSyncSummary();
        $this->info('📈 Overall Statistics:');
        $this->line("   Total Projects: {$summary['total_projects']}");
        $this->line("   Completed Syncs: {$summary['completed_syncs']}");
        $this->line("   Failed Syncs: {$summary['failed_syncs']}");
        $this->line("   In Progress: {$summary['in_progress_syncs']}");
        $this->line("   Success Rate: {$summary['success_rate']}%");

        return Command::SUCCESS;
    }
}
