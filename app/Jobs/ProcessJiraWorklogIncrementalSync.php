<?php

namespace App\Jobs;

use App\Events\JiraSyncProgress;
use App\Models\JiraSyncHistory;
use App\Services\JiraWorklogIncrementalSyncService;
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

class ProcessJiraWorklogIncrementalSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $syncOptions;
    public ?int $syncHistoryId;

    // Job configuration for worklog sync (lighter than full sync)
    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 1800; // 30 minutes (should be much faster than full sync)
    public int $retryAfter = 180; // 3 minutes between retries

    /**
     * Create a new incremental worklog sync job.
     */
    public function __construct(array $syncOptions = [], ?int $syncHistoryId = null)
    {
        $this->syncOptions = array_merge([
            'sync_type' => 'worklog_incremental',
            'incremental' => true,
            'project_keys' => [],
            'since_date' => null,
        ], $syncOptions);

        $this->syncHistoryId = $syncHistoryId;

        // Use dedicated worklog sync queue
        $this->onQueue('jira-worklog-sync');
    }

    /**
     * Execute the incremental worklog sync job.
     */
    public function handle(
        JiraWorklogIncrementalSyncService $worklogSyncService,
        JiraSyncProgressService $progressService
    ): void {
        Log::info('🔄 Starting incremental worklog sync job', [
            'sync_options' => $this->syncOptions,
            'sync_history_id' => $this->syncHistoryId,
        ]);

        $syncHistory = null;
        if ($this->syncHistoryId) {
            $syncHistory = JiraSyncHistory::find($this->syncHistoryId);
        }

        try {
            // Validate sync options
            $projectKeys = $this->syncOptions['project_keys'] ?? [];
            if (empty($projectKeys)) {
                throw new Exception('No project keys specified for worklog sync');
            }

            // Parse since date if provided
            $sinceDate = null;
            if (!empty($this->syncOptions['since_date'])) {
                $sinceDate = Carbon::parse($this->syncOptions['since_date']);
            }

            // Update sync history status
            if ($syncHistory) {
                $syncHistory->update(['status' => 'in_progress']);
                $progressService->broadcastProgress(
                    $syncHistory,
                    'Starting incremental worklog sync...'
                );
            }

            // Perform the incremental worklog sync
            $results = $worklogSyncService->syncWorklogsIncremental(
                $projectKeys,
                $sinceDate,
                $syncHistory
            );

            // Update sync history with results
            if ($syncHistory) {
                $syncHistory->update([
                    'status' => empty($results['errors']) ? 'completed' : 'completed_with_errors',
                    'completed_at' => now(),
                    'error_count' => count($results['errors'] ?? []),
                    'error_details' => !empty($results['errors']) ? $results['errors'] : null,
                    'total_worklogs' => $results['worklogs_processed'] ?? 0,
                    'processed_worklogs' => $results['worklogs_processed'] ?? 0,
                    'metadata' => array_merge($syncHistory->metadata ?? [], [
                        'worklog_sync_results' => $results,
                        'sync_duration' => $syncHistory->created_at->diffInSeconds(now()),
                    ]),
                ]);

                // Final progress broadcast
                $successMessage = sprintf(
                    'Worklog sync completed! Processed: %d, Added: %d, Updated: %d',
                    $results['worklogs_processed'],
                    $results['worklogs_added'],
                    $results['worklogs_updated']
                );

                $progressService->broadcastProgress($syncHistory, $successMessage, true);
            }

            Log::info('✅ Incremental worklog sync completed successfully', $results);

        } catch (Exception $e) {
            $this->handleSyncFailure($e, $syncHistory, $progressService);
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('❌ Incremental worklog sync job failed', [
            'exception' => $exception->getMessage(),
            'sync_options' => $this->syncOptions,
            'sync_history_id' => $this->syncHistoryId,
        ]);

        if ($this->syncHistoryId) {
            $syncHistory = JiraSyncHistory::find($this->syncHistoryId);
            if ($syncHistory) {
                $syncHistory->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_count' => 1,
                    'error_details' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle sync failure and update sync history.
     */
    protected function handleSyncFailure(
        Exception $exception,
        ?JiraSyncHistory $syncHistory,
        JiraSyncProgressService $progressService
    ): void {
        $errorMessage = 'Incremental worklog sync failed: ' . $exception->getMessage();
        
        Log::error($errorMessage, [
            'exception' => $exception,
            'sync_options' => $this->syncOptions,
        ]);

        if ($syncHistory) {
            $syncHistory->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_count' => 1,
                'error_details' => $exception->getMessage(),
            ]);

            $progressService->broadcastProgress(
                $syncHistory,
                'Worklog sync failed: ' . $exception->getMessage(),
                true
            );
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'jira-sync',
            'worklog-sync',
            'incremental',
            'project:' . implode(',', $this->syncOptions['project_keys'] ?? []),
        ];
    }

    /**
     * Calculate job priority based on sync options.
     */
    public function priority(): int
    {
        // Higher priority for manual triggers
        if (isset($this->syncOptions['manual']) && $this->syncOptions['manual']) {
            return 100;
        }

        // Medium priority for automated daily syncs
        if (isset($this->syncOptions['automated']) && $this->syncOptions['automated']) {
            return 50;
        }

        // Default priority
        return 10;
    }

    /**
     * Determine if the job should be retried.
     */
    public function retryUntil(): Carbon
    {
        // Allow retries for up to 1 hour
        return now()->addHour();
    }
}