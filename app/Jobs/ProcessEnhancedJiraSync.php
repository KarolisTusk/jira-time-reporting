<?php

namespace App\Jobs;

use App\Events\EnhancedJiraSyncProgress;
use App\Models\JiraSyncHistory;
use App\Services\EnhancedJiraImportService;
use App\Services\JiraSyncCheckpointService;
use App\Services\JiraSyncProgressService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEnhancedJiraSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $syncOptions;
    public ?int $syncHistoryId;

    // Job configuration for reliability
    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 14400; // 4 hours timeout (FIXED: prevent incomplete large syncs)
    public int $retryAfter = 300; // 5 minutes between retries

    /**
     * Create a new job instance.
     */
    public function __construct(array $syncOptions = [])
    {
        $this->syncOptions = $syncOptions;
        $this->syncHistoryId = $syncOptions['sync_history_id'] ?? null;
        
        // Set queue priority based on sync type
        $this->onQueue($syncOptions['sync_type'] === 'manual' ? 'high' : 'default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessEnhancedJiraSync job started', [
            'sync_history_id' => $this->syncHistoryId,
            'options' => $this->syncOptions,
            'attempt' => $this->attempts(),
        ]);

        $syncHistory = null;
        $progressService = null;

        try {
            // Initialize services
            $importService = app(EnhancedJiraImportService::class);
            $checkpointService = app(JiraSyncCheckpointService::class);
            $progressService = app(JiraSyncProgressService::class);

            // Find or create sync history
            $syncHistory = $this->getSyncHistory();
            
            // Initialize progress service with sync history
            $progressService->setSyncHistory($syncHistory);
            
            // Mark as started and broadcast initial progress
            $syncHistory->update([
                'status' => 'in_progress',
                'current_operation' => 'Starting enhanced JIRA sync job...',
                'progress_percentage' => 1,
            ]);
            
            $progressService->broadcastProgress($syncHistory, 'Enhanced JIRA sync job started');

            // Validate that we have projects to sync
            if (empty($this->syncOptions['project_keys'])) {
                throw new Exception('No project keys specified for sync operation');
            }

            // Check if this is a retry and attempt recovery
            if ($this->attempts() > 1) {
                $this->attemptRecoveryFromCheckpoint($syncHistory, $checkpointService);
            }

            // Apply rate limiting configuration
            $this->applyRateLimiting();

            // Update progress to show we're starting actual work
            $syncHistory->update([
                'current_operation' => 'Initializing data import process...',
                'progress_percentage' => 5,
            ]);
            $progressService->broadcastProgress($syncHistory, 'Starting data import process');

            // Execute the sync with enhanced options
            $result = $importService->importDataWithOptions(array_merge($this->syncOptions, [
                'sync_history_id' => $syncHistory->id,
                'job_attempt' => $this->attempts(),
            ]));

            // Handle successful completion
            if ($result['success']) {
                $this->handleSuccessfulSync($syncHistory, $result, $progressService);
            } else {
                $this->handleFailedSync($syncHistory, $result, $progressService);
            }

        } catch (Exception $e) {
            Log::error('ProcessEnhancedJiraSync job exception', [
                'sync_history_id' => $this->syncHistoryId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts(),
            ]);
            
            $this->handleJobException($e, $syncHistory, $progressService);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessEnhancedJiraSync job failed completely', [
            'sync_history_id' => $this->syncHistoryId,
            'exception' => $exception?->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        try {
            $syncHistory = $this->getSyncHistory();
            
            if ($syncHistory) {
                $syncHistory->markAsFailed([
                    'job_failed' => true,
                    'final_attempt' => $this->attempts(),
                    'exception' => $exception?->getMessage(),
                    'trace' => $exception?->getTraceAsString(),
                ]);

                // Broadcast failure
                $progressService = app(JiraSyncProgressService::class);
                $progressService->broadcastProgress(
                    $syncHistory, 
                    'Sync job failed after ' . $this->attempts() . ' attempts: ' . ($exception?->getMessage() ?? 'Unknown error')
                );
            }
        } catch (Exception $e) {
            Log::error('Failed to handle job failure: ' . $e->getMessage());
        }
    }

    /**
     * Get or create sync history record.
     */
    protected function getSyncHistory(): JiraSyncHistory
    {
        if ($this->syncHistoryId) {
            return JiraSyncHistory::findOrFail($this->syncHistoryId);
        }

        // Create new sync history if not provided
        return JiraSyncHistory::create([
            'started_at' => now(),
            'status' => 'pending',
            'sync_type' => $this->syncOptions['sync_type'] ?? 'job',
            'triggered_by' => $this->syncOptions['triggered_by'] ?? null,
            'total_projects' => 0,
            'processed_projects' => 0,
            'total_issues' => 0,
            'processed_issues' => 0,
            'total_worklogs' => 0,
            'processed_worklogs' => 0,
            'total_users' => 0,
            'processed_users' => 0,
            'error_count' => 0,
            'progress_percentage' => 0,
            'current_operation' => 'Job queued for processing...',
        ]);
    }

    /**
     * Attempt to recover from checkpoint if this is a retry.
     */
    protected function attemptRecoveryFromCheckpoint(JiraSyncHistory $syncHistory, JiraSyncCheckpointService $checkpointService): void
    {
        Log::info("Attempting recovery for sync {$syncHistory->id}, attempt {$this->attempts()}");

        $resumeData = $checkpointService->resumeFromCheckpoint($syncHistory->id);
        
        if ($resumeData['can_resume'] && $resumeData['resume_strategy'] === 'partial_resume') {
            Log::info("Partial resume possible for sync {$syncHistory->id}", $resumeData);
            
            // Update sync options to resume from checkpoint
            $this->syncOptions['resume_from_checkpoint'] = true;
            $this->syncOptions['projects_to_retry'] = $resumeData['projects_to_retry'];
            $this->syncOptions['completed_projects'] = $resumeData['completed_projects'];
            
            // Reset sync status to in_progress for retry
            $syncHistory->update([
                'status' => 'in_progress',
                'current_operation' => "Resuming from checkpoint (attempt {$this->attempts()})...",
            ]);
        } else {
            Log::info("Full restart required for sync {$syncHistory->id}, reason: {$resumeData['resume_strategy']}");
        }
    }

    /**
     * Apply intelligent rate limiting based on JIRA API best practices.
     */
    protected function applyRateLimiting(): void
    {
        // Implement exponential backoff for retries
        if ($this->attempts() > 1) {
            $delay = min(300, 30 * pow(2, $this->attempts() - 1)); // Max 5 minutes delay
            Log::info("Applying retry delay: {$delay} seconds for attempt {$this->attempts()}");
            sleep($delay);
        }

        // Apply additional rate limiting based on sync type
        if (($this->syncOptions['sync_type'] ?? 'manual') === 'automated') {
            // Be more conservative with automated syncs
            sleep(10); // 10 second delay for automated syncs
        }
    }

    /**
     * Handle successful sync completion.
     */
    protected function handleSuccessfulSync(JiraSyncHistory $syncHistory, array $result, JiraSyncProgressService $progressService): void
    {
        Log::info("Enhanced JIRA sync completed successfully", [
            'sync_history_id' => $syncHistory->id,
            'projects_processed' => $result['projects_processed'],
            'issues_processed' => $result['issues_processed'],
            'worklogs_imported' => $result['worklogs_imported'],
            'total_hours_imported' => $result['total_hours_imported'],
        ]);

        // Update sync history with final results
        $syncHistory->update([
            'total_projects' => $result['projects_processed'],
            'processed_projects' => $result['projects_processed'],
            'total_issues' => $result['issues_processed'],
            'processed_issues' => $result['issues_processed'],
            'total_worklogs' => $result['worklogs_imported'],
            'processed_worklogs' => $result['worklogs_imported'],
            'total_users' => $result['users_processed'],
            'processed_users' => $result['users_processed'],
        ]);

        // Mark as completed
        $syncHistory->markAsCompleted();

        // Broadcast final success
        $progressService->broadcastProgress($syncHistory, 
            "Sync completed! Imported {$result['worklogs_imported']} worklogs " .
            "({$result['total_hours_imported']} hours) from {$result['issues_processed']} issues."
        );

        // Log validation metrics
        Log::info("Sync validation metrics", [
            'total_hours_imported' => $result['total_hours_imported'],
            'sync_history_id' => $syncHistory->id,
            'job_duration' => $syncHistory->duration_seconds,
        ]);
    }

    /**
     * Handle failed sync.
     */
    protected function handleFailedSync(JiraSyncHistory $syncHistory, array $result, JiraSyncProgressService $progressService): void
    {
        Log::error("Enhanced JIRA sync failed", [
            'sync_history_id' => $syncHistory->id,
            'errors' => $result['errors'],
            'partial_results' => [
                'projects_processed' => $result['projects_processed'],
                'issues_processed' => $result['issues_processed'],
                'worklogs_imported' => $result['worklogs_imported'],
            ],
        ]);

        // Update sync history with partial results
        $syncHistory->update([
            'total_projects' => $result['projects_processed'],
            'processed_projects' => $result['projects_processed'],
            'total_issues' => $result['issues_processed'],
            'processed_issues' => $result['issues_processed'],
            'total_worklogs' => $result['worklogs_imported'],
            'processed_worklogs' => $result['worklogs_imported'],
            'error_count' => count($result['errors']),
        ]);

        // Mark as failed
        $syncHistory->markAsFailed($result['errors']);

        // Broadcast failure
        $progressService->broadcastProgress($syncHistory, 
            "Sync failed with " . count($result['errors']) . " errors. " .
            "Partial results: {$result['issues_processed']} issues, {$result['worklogs_imported']} worklogs."
        );

        // If this job should retry, don't mark it as completely failed yet
        if ($this->attempts() < $this->tries) {
            throw new Exception("Sync failed, will retry. Errors: " . implode('; ', array_slice($result['errors'], 0, 3)));
        }
    }

    /**
     * Handle job-level exceptions.
     */
    protected function handleJobException(Exception $e, ?JiraSyncHistory $syncHistory = null, ?JiraSyncProgressService $progressService = null): void
    {
        Log::error("ProcessEnhancedJiraSync job exception", [
            'sync_history_id' => $this->syncHistoryId,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempt' => $this->attempts(),
        ]);

        try {
            if ($syncHistory) {
                $syncHistory->addError("Job exception: " . $e->getMessage(), [
                    'exception_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'attempt' => $this->attempts(),
                ]);

                // If this is not the final attempt, prepare for retry
                if ($this->attempts() < $this->tries) {
                    $syncHistory->updateCurrentOperation(
                        "Sync failed (attempt {$this->attempts()}), will retry in " . $this->retryAfter . " seconds..."
                    );
                    
                    if ($progressService) {
                        $progressService->broadcastProgress($syncHistory, "Sync failed, will retry in " . $this->retryAfter . " seconds");
                    }
                } else {
                    // Final attempt failed
                    if ($progressService) {
                        $progressService->broadcastProgress($syncHistory, 'Sync failed after all retry attempts');
                    }
                }
            }
        } catch (Exception $nested) {
            Log::error("Failed to handle job exception: " . $nested->getMessage());
        }

        // Re-throw to trigger Laravel's retry mechanism
        throw $e;
    }

    /**
     * Calculate backoff delay for retries.
     */
    public function backoff(): array
    {
        // Exponential backoff: 5min, 10min, 15min
        return [300, 600, 900];
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        // Allow retries for up to 2 hours
        return now()->addHours(2);
    }

    /**
     * Get tags for monitoring and debugging.
     */
    public function tags(): array
    {
        return [
            'enhanced-jira-sync',
            'sync-history:' . ($this->syncHistoryId ?? 'new'),
            'sync-type:' . ($this->syncOptions['sync_type'] ?? 'manual'),
            'attempt:' . $this->attempts(),
        ];
    }
}