<?php

namespace App\Jobs;

use App\Models\JiraProjectSyncStatus;
use App\Models\JiraSetting;
use App\Models\JiraSyncHistory;
use App\Services\JiraSyncProgressService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutomatedDailyJiraSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?array $projectKeys;
    public bool $forceSync;

    // Job configuration
    public int $tries = 2;
    public int $maxExceptions = 2;
    public int $timeout = 7200; // 2 hours timeout for daily sync
    public int $retryAfter = 1800; // 30 minutes between retries

    /**
     * Create a new job instance.
     */
    public function __construct(?array $projectKeys = null, bool $forceSync = false)
    {
        $this->projectKeys = $projectKeys;
        $this->forceSync = $forceSync;
        
        // Always use default queue for automated syncs with lower priority
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('AutomatedDailyJiraSync job started', [
            'project_keys' => $this->projectKeys,
            'force_sync' => $this->forceSync,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Check if JIRA settings are configured
            $settings = $this->validateJiraSettings();
            
            // Determine which projects need syncing
            $projectsToSync = $this->determineProjectsToSync($settings);
            
            if (empty($projectsToSync)) {
                Log::info('No projects need daily sync at this time');
                return;
            }

            Log::info('Daily sync will process projects', [
                'projects' => $projectsToSync,
                'total_count' => count($projectsToSync),
            ]);

            // Create sync history for this automated run
            $syncHistory = $this->createAutomatedSyncHistory($projectsToSync);
            
            // Dispatch enhanced sync job for each project or all at once
            $this->dispatchEnhancedSync($projectsToSync, $syncHistory);
            
            Log::info('Daily sync jobs dispatched successfully', [
                'sync_history_id' => $syncHistory->id,
                'projects_count' => count($projectsToSync),
            ]);

        } catch (Exception $e) {
            $this->handleJobException($e);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('AutomatedDailyJiraSync job failed completely', [
            'exception' => $exception?->getMessage(),
            'attempts' => $this->attempts(),
            'project_keys' => $this->projectKeys,
        ]);

        // Mark project sync statuses as failed if we can identify them
        if ($this->projectKeys) {
            foreach ($this->projectKeys as $projectKey) {
                $projectStatus = JiraProjectSyncStatus::where('project_key', $projectKey)->first();
                if ($projectStatus) {
                    $projectStatus->markSyncFailed(
                        'Daily automated sync job failed: ' . ($exception?->getMessage() ?? 'Unknown error'),
                        ['automated_sync_failure' => true, 'failed_at' => now()->toISOString()]
                    );
                }
            }
        }
    }

    /**
     * Validate JIRA settings are available.
     */
    protected function validateJiraSettings(): JiraSetting
    {
        $settings = JiraSetting::first();
        
        if (!$settings || empty($settings->project_keys)) {
            throw new Exception('JIRA settings not configured or no project keys specified for daily sync');
        }

        if (!$settings->jira_host || !$settings->api_token) {
            throw new Exception('JIRA connection settings incomplete (missing host or API token)');
        }

        return $settings;
    }

    /**
     * Determine which projects need daily syncing.
     */
    protected function determineProjectsToSync(JiraSetting $settings): array
    {
        // If specific project keys provided, use those (for testing or manual runs)
        if ($this->projectKeys) {
            Log::info('Using provided project keys for daily sync', ['keys' => $this->projectKeys]);
            return $this->projectKeys;
        }

        // Get all configured project keys
        $allProjectKeys = $settings->project_keys ?? [];
        $projectsToSync = [];

        foreach ($allProjectKeys as $projectKey) {
            if ($this->shouldSyncProject($projectKey)) {
                $projectsToSync[] = $projectKey;
            }
        }

        return $projectsToSync;
    }

    /**
     * Check if a specific project should be synced today.
     */
    protected function shouldSyncProject(string $projectKey): bool
    {
        // If force sync is enabled, sync all projects
        if ($this->forceSync) {
            Log::debug("Force sync enabled, will sync project {$projectKey}");
            return true;
        }

        $projectStatus = JiraProjectSyncStatus::where('project_key', $projectKey)->first();
        
        // If no sync status exists, this project needs initial sync
        if (!$projectStatus) {
            Log::info("Project {$projectKey} has no sync status, needs initial sync");
            return true;
        }

        // Check if project is due for sync (default 24 hours)
        $isDue = $projectStatus->isDueForSync(24);
        
        if ($isDue) {
            $reason = $projectStatus->last_sync_at 
                ? "Last sync was {$projectStatus->time_since_last_sync}"
                : 'Never synced';
            
            Log::info("Project {$projectKey} is due for sync", [
                'reason' => $reason,
                'last_sync_status' => $projectStatus->last_sync_status,
                'last_sync_at' => $projectStatus->last_sync_at?->toISOString(),
            ]);
        } else {
            Log::debug("Project {$projectKey} does not need sync yet", [
                'last_sync_at' => $projectStatus->last_sync_at?->toISOString(),
                'last_sync_status' => $projectStatus->last_sync_status,
            ]);
        }

        return $isDue;
    }

    /**
     * Create sync history record for automated sync.
     */
    protected function createAutomatedSyncHistory(array $projectKeys): JiraSyncHistory
    {
        return JiraSyncHistory::create([
            'started_at' => now(),
            'status' => 'pending',
            'sync_type' => 'automated_daily',
            'triggered_by' => null, // System-triggered
            'total_projects' => count($projectKeys),
            'processed_projects' => 0,
            'total_issues' => 0,
            'processed_issues' => 0,
            'total_worklogs' => 0,
            'processed_worklogs' => 0,
            'total_users' => 0,
            'processed_users' => 0,
            'error_count' => 0,
            'progress_percentage' => 0,
            'current_operation' => 'Daily automated sync scheduled...',
            'error_details' => [
                'automated_sync_metadata' => [
                    'project_keys' => $projectKeys,
                    'scheduled_at' => now()->toISOString(),
                    'force_sync' => $this->forceSync,
                ]
            ],
        ]);
    }

    /**
     * Dispatch the enhanced sync job(s).
     */
    protected function dispatchEnhancedSync(array $projectKeys, JiraSyncHistory $syncHistory): void
    {
        // Mark project sync statuses as in progress
        foreach ($projectKeys as $projectKey) {
            $projectStatus = JiraProjectSyncStatus::firstOrCreate(
                ['project_key' => $projectKey],
                [
                    'last_sync_at' => null,
                    'last_sync_status' => 'pending',
                    'issues_count' => 0,
                    'last_error' => null,
                ]
            );
            
            $projectStatus->markSyncStarted();
        }

        // Configure sync options for automated daily sync
        $syncOptions = [
            'sync_type' => 'automated_daily',
            'project_keys' => $projectKeys,
            'only_issues_with_worklogs' => false, // Include all issues for complete coverage
            'date_range' => null, // Use incremental logic based on last sync timestamps
            'triggered_by' => null, // System triggered
            'automated_sync' => true,
        ];

        // Dispatch the enhanced sync job with delay to avoid peak hours
        $delay = $this->calculateOptimalDelay();
        
        ProcessEnhancedJiraSync::dispatch($syncOptions, $syncHistory->id)
            ->delay($delay)
            ->onQueue('default'); // Use default queue for automated syncs

        Log::info('Enhanced sync job dispatched for daily automation', [
            'sync_history_id' => $syncHistory->id,
            'delay_minutes' => $delay,
            'project_count' => count($projectKeys),
        ]);

        // Update sync history with dispatch info
        $syncHistory->updateCurrentOperation(
            "Enhanced sync job dispatched for {count($projectKeys)} projects (delayed {$delay} minutes)"
        );
    }

    /**
     * Calculate optimal delay for sync to avoid peak usage hours.
     */
    protected function calculateOptimalDelay(): int
    {
        $currentHour = now()->hour;
        
        // If it's during business hours (8 AM - 6 PM), delay until off-hours
        if ($currentHour >= 8 && $currentHour < 18) {
            // Delay until 6 PM
            $delayHours = 18 - $currentHour;
            $delayMinutes = $delayHours * 60;
            
            Log::info("Delaying automated sync to avoid business hours", [
                'current_hour' => $currentHour,
                'delay_hours' => $delayHours,
                'will_run_at' => now()->addMinutes($delayMinutes)->format('Y-m-d H:i'),
            ]);
            
            return $delayMinutes;
        }
        
        // If it's already off-hours, run with minimal delay (5 minutes)
        return 5;
    }

    /**
     * Handle job-level exceptions.
     */
    protected function handleJobException(Exception $e): void
    {
        Log::error("AutomatedDailyJiraSync job exception", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempt' => $this->attempts(),
            'project_keys' => $this->projectKeys,
        ]);

        // If this is not the final attempt, log retry info
        if ($this->attempts() < $this->tries) {
            Log::info("Daily sync job will retry in {$this->retryAfter} seconds", [
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);
        }

        // Re-throw to trigger Laravel's retry mechanism
        throw $e;
    }

    /**
     * Calculate backoff delay for retries.
     */
    public function backoff(): array
    {
        // For daily sync, use longer delays: 30min, 1hour
        return [1800, 3600];
    }

    /**
     * Determine retry deadline.
     */
    public function retryUntil(): \DateTime
    {
        // Allow retries for up to 4 hours for daily sync
        return now()->addHours(4);
    }

    /**
     * Get tags for monitoring.
     */
    public function tags(): array
    {
        return [
            'automated-daily-sync',
            'sync-type:automated',
            'attempt:' . $this->attempts(),
            'projects:' . count($this->projectKeys ?? []),
        ];
    }

    /**
     * Check if daily sync is already running.
     */
    public static function isDailySyncRunning(): bool
    {
        // Check if there's an in-progress automated sync from the last 4 hours
        $recentSync = JiraSyncHistory::where('sync_type', 'automated_daily')
            ->where('status', 'in_progress')
            ->where('started_at', '>=', now()->subHours(4))
            ->exists();

        return $recentSync;
    }

    /**
     * Get the last daily sync summary.
     */
    public static function getLastDailySyncSummary(): ?array
    {
        $lastSync = JiraSyncHistory::where('sync_type', 'automated_daily')
            ->latest('started_at')
            ->first();

        if (!$lastSync) {
            return null;
        }

        return [
            'id' => $lastSync->id,
            'status' => $lastSync->status,
            'started_at' => $lastSync->started_at->toISOString(),
            'completed_at' => $lastSync->completed_at?->toISOString(),
            'duration' => $lastSync->formatted_duration,
            'projects_processed' => $lastSync->processed_projects,
            'total_projects' => $lastSync->total_projects,
            'issues_processed' => $lastSync->processed_issues,
            'worklogs_imported' => $lastSync->processed_worklogs,
            'error_count' => $lastSync->error_count,
            'success_rate' => $lastSync->total_projects > 0 
                ? round(($lastSync->processed_projects / $lastSync->total_projects) * 100, 2)
                : 0,
        ];
    }
}