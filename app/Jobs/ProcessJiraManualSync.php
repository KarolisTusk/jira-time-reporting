<?php

namespace App\Jobs;

use App\Services\EnhancedJiraImportService;
use App\Services\JiraSyncCacheService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessJiraManualSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes (PRD: monthly sync < 30 min)
    public int $tries = 3; // PRD: 95% error recovery target
    public int $maxExceptions = 3;

    protected array $syncOptions;
    protected string $userId;
    protected string $syncId;

    /**
     * Create a new job instance for manual sync (PRD: project manager control).
     */
    public function __construct(array $syncOptions, string $userId, string $syncId = null)
    {
        $this->syncOptions = $syncOptions;
        $this->userId = $userId;
        $this->syncId = $syncId ?? uniqid('manual_sync_');
        
        // Use high priority queue for manual operations (PRD requirement)
        $this->onQueue('jira-sync-high');
    }

    /**
     * Execute the manual sync job (PRD: granular control with date ranges and project selection).
     */
    public function handle(EnhancedJiraImportService $importService, JiraSyncCacheService $cacheService): void
    {
        $startTime = microtime(true);
        
        Log::info('Manual JIRA sync job started', [
            'sync_id' => $this->syncId,
            'user_id' => $this->userId,
            'options' => $this->syncOptions,
            'prd_target' => '< 30 minutes completion',
            'job_queue' => 'jira-sync-high'
        ]);

        try {
            // Clear relevant cache before sync to ensure fresh data
            if (isset($this->syncOptions['project_keys'])) {
                foreach ($this->syncOptions['project_keys'] as $projectKey) {
                    $cacheService->invalidateProjectCache($projectKey);
                }
            }

            // Execute the enhanced sync with manual options
            $results = $importService->importDataWithOptions($this->syncOptions);
            
            $duration = microtime(true) - $startTime;
            $durationMinutes = round($duration / 60, 2);
            
            // Validate against PRD performance targets
            $prdCompliant = $durationMinutes <= 30; // PRD: < 30 minutes target
            
            Log::info('Manual JIRA sync completed', [
                'sync_id' => $this->syncId,
                'duration_minutes' => $durationMinutes,
                'prd_compliant' => $prdCompliant,
                'results' => [
                    'success' => $results['success'],
                    'projects_processed' => $results['projects_processed'],
                    'issues_processed' => $results['issues_processed'],
                    'worklogs_imported' => $results['worklogs_imported'],
                    'total_hours_imported' => $results['total_hours_imported'],
                ],
                'performance_notes' => $prdCompliant ? 'Meets PRD target' : 'Exceeds PRD target - optimization needed'
            ]);

            // Broadcast completion for real-time monitoring (PRD requirement)
            $this->broadcastSyncCompletion($results, $durationMinutes);

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            Log::error('Manual JIRA sync failed', [
                'sync_id' => $this->syncId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 2),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries
            ]);

            // Re-throw to trigger retry mechanism (PRD: intelligent retry)
            throw $e;
        }
    }

    /**
     * Handle job failure (PRD: comprehensive error reporting).
     */
    public function failed(Exception $exception): void
    {
        Log::error('Manual JIRA sync job failed permanently', [
            'sync_id' => $this->syncId,
            'user_id' => $this->userId,
            'final_error' => $exception->getMessage(),
            'attempts_made' => $this->attempts(),
            'sync_options' => $this->syncOptions,
            'prd_impact' => 'Manual sync unavailable - may affect monthly reporting'
        ]);

        // Broadcast failure for user notification
        $this->broadcastSyncFailure($exception);
    }

    /**
     * Calculate delay between retry attempts (exponential backoff).
     */
    public function backoff(): array
    {
        // PRD: intelligent retry with exponential backoff
        return [30, 120, 300]; // 30s, 2min, 5min delays
    }

    /**
     * Determine if the job should be retried.
     */
    public function shouldRetry(Exception $exception): bool
    {
        // Don't retry on certain permanent failures
        $permanentErrors = [
            'Invalid project key',
            'Authentication failed',
            'JIRA instance not accessible'
        ];

        foreach ($permanentErrors as $permanentError) {
            if (str_contains($exception->getMessage(), $permanentError)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the tags for job monitoring.
     */
    public function tags(): array
    {
        return [
            'jira-sync',
            'manual',
            'high-priority',
            'user:' . $this->userId,
            'sync:' . $this->syncId,
            'prd-critical'
        ];
    }

    /**
     * Broadcast sync completion for real-time monitoring.
     */
    private function broadcastSyncCompletion(array $results, float $durationMinutes): void
    {
        // In a real implementation, this would broadcast via WebSocket
        // For now, we'll dispatch a real-time notification job
        ProcessJiraRealTimeNotification::dispatch([
            'type' => 'sync_completed',
            'sync_id' => $this->syncId,
            'user_id' => $this->userId,
            'duration_minutes' => $durationMinutes,
            'results' => $results,
            'prd_compliant' => $durationMinutes <= 30
        ])->onQueue('jira-realtime');
    }

    /**
     * Broadcast sync failure for user notification.
     */
    private function broadcastSyncFailure(Exception $exception): void
    {
        ProcessJiraRealTimeNotification::dispatch([
            'type' => 'sync_failed',
            'sync_id' => $this->syncId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
            'retry_available' => $this->attempts() < $this->tries
        ])->onQueue('jira-realtime');
    }
}
