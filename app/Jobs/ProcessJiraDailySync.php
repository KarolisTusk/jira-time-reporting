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

class ProcessJiraDailySync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes for incremental daily syncs
    public int $tries = 5; // More retries for daily automation (PRD requirement)
    public int $maxExceptions = 5;

    protected array $syncOptions;
    protected string $syncId;
    protected bool $isScheduled;

    /**
     * Create a new daily sync job (PRD: automated daily incremental sync).
     */
    public function __construct(array $syncOptions = [], bool $isScheduled = true)
    {
        $this->syncOptions = array_merge([
            'incremental' => true,
            'sync_type' => 'daily_automated',
            'since_date' => now()->subDay()->toDateString(), // Last 24 hours
        ], $syncOptions);
        
        $this->isScheduled = $isScheduled;
        $this->syncId = uniqid('daily_sync_');
        
        // Use daily sync queue for automated operations
        $this->onQueue('jira-sync-daily');
    }

    /**
     * Execute the daily sync job (PRD: incremental sync with business hours avoidance).
     */
    public function handle(EnhancedJiraImportService $importService, JiraSyncCacheService $cacheService): void
    {
        $startTime = microtime(true);
        
        Log::info('Daily JIRA sync job started', [
            'sync_id' => $this->syncId,
            'is_scheduled' => $this->isScheduled,
            'options' => $this->syncOptions,
            'prd_target' => 'Incremental sync within 15 minutes',
            'queue' => 'jira-sync-daily'
        ]);

        try {
            // Validate timing - avoid business hours if this is a scheduled sync
            if ($this->isScheduled && $this->isBusinessHours()) {
                Log::info('Daily sync delayed - avoiding business hours', [
                    'sync_id' => $this->syncId,
                    'current_time' => now()->toTimeString(),
                    'prd_requirement' => 'Business hours avoidance'
                ]);
                
                // Reschedule for off-business hours
                $this->release($this->calculateDelayToOffHours());
                return;
            }

            // Broadcast sync start for monitoring
            $this->broadcastDailySyncStarted();

            // Execute incremental sync with daily-specific options
            $results = $importService->importDataWithOptions($this->syncOptions);
            
            $duration = microtime(true) - $startTime;
            $durationMinutes = round($duration / 60, 2);
            
            // Validate against PRD performance targets (15 minutes for daily sync)
            $prdCompliant = $durationMinutes <= 15;
            
            Log::info('Daily JIRA sync completed', [
                'sync_id' => $this->syncId,
                'duration_minutes' => $durationMinutes,
                'prd_compliant' => $prdCompliant,
                'sync_type' => 'daily_automated',
                'results' => [
                    'success' => $results['success'],
                    'projects_processed' => $results['projects_processed'],
                    'new_issues' => $results['new_issues_imported'] ?? 0,
                    'updated_issues' => $results['updated_issues'] ?? 0,
                    'new_worklogs' => $results['new_worklogs_imported'] ?? 0,
                    'updated_worklogs' => $results['updated_worklogs'] ?? 0,
                    'total_hours_imported' => $results['total_hours_imported'],
                ],
                'performance_notes' => $prdCompliant ? 'Meets PRD daily sync target' : 'Exceeds PRD target - optimization needed'
            ]);

            // Warm cache for frequently accessed data after successful sync
            $this->warmDailySyncCache($cacheService, $results);

            // Broadcast completion for monitoring and dashboards
            $this->broadcastDailySyncCompleted($results, $durationMinutes);

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            Log::error('Daily JIRA sync failed', [
                'sync_id' => $this->syncId,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 2),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'sync_type' => 'daily_automated'
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle job failure (PRD: comprehensive error reporting for daily automation).
     */
    public function failed(Exception $exception): void
    {
        Log::error('Daily JIRA sync job failed permanently', [
            'sync_id' => $this->syncId,
            'final_error' => $exception->getMessage(),
            'attempts_made' => $this->attempts(),
            'sync_options' => $this->syncOptions,
            'is_scheduled' => $this->isScheduled,
            'prd_impact' => 'Daily automation unavailable - manual sync may be required',
            'next_scheduled_sync' => now()->addDay()->toDateTimeString()
        ]);

        // Broadcast failure for system monitoring
        ProcessJiraRealTimeNotification::dispatch([
            'type' => 'daily_sync_failed',
            'sync_id' => $this->syncId,
            'error' => $exception->getMessage(),
            'is_scheduled' => $this->isScheduled,
            'next_retry' => now()->addDay()->toDateTimeString()
        ])->onQueue('jira-realtime');
    }

    /**
     * Calculate delay between retry attempts (exponential backoff).
     */
    public function backoff(): array
    {
        // PRD: intelligent retry with longer delays for daily automation
        return [60, 300, 900, 1800, 3600]; // 1min, 5min, 15min, 30min, 1hour
    }

    /**
     * Determine if the job should be retried.
     */
    public function shouldRetry(Exception $exception): bool
    {
        // Don't retry certain permanent failures
        $permanentErrors = [
            'Invalid project configuration',
            'JIRA authentication expired',
            'JIRA instance maintenance mode'
        ];

        foreach ($permanentErrors as $permanentError) {
            if (str_contains($exception->getMessage(), $permanentError)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if current time is within business hours.
     */
    private function isBusinessHours(): bool
    {
        $now = now();
        $hour = $now->hour;
        $dayOfWeek = $now->dayOfWeek;
        
        // Business hours: Monday-Friday, 8 AM - 6 PM
        // Avoid sync during these hours for scheduled syncs
        $isWeekday = $dayOfWeek >= Carbon::MONDAY && $dayOfWeek <= Carbon::FRIDAY;
        $isBusinessHour = $hour >= 8 && $hour < 18;
        
        return $isWeekday && $isBusinessHour;
    }

    /**
     * Calculate delay to off-business hours.
     */
    private function calculateDelayToOffHours(): int
    {
        $now = now();
        
        // If it's during business hours on weekday, delay until 6 PM
        if ($this->isBusinessHours()) {
            $targetTime = $now->copy()->setTime(18, 0, 0);
            if ($targetTime->isPast()) {
                $targetTime->addDay();
            }
        } else if ($now->dayOfWeek >= Carbon::MONDAY && $now->dayOfWeek <= Carbon::FRIDAY && $now->hour < 8) {
            // If it's early morning on weekday, delay until 6 PM same day
            $targetTime = $now->copy()->setTime(18, 0, 0);
        } else {
            // Weekend or after hours - can run immediately
            return 0;
        }
        
        return $targetTime->diffInSeconds($now);
    }

    /**
     * Warm cache with daily sync results.
     */
    private function warmDailySyncCache(JiraSyncCacheService $cacheService, array $results): void
    {
        try {
            // Warm cache for projects that were updated
            if (isset($results['projects_processed']) && is_array($results['projects_processed'])) {
                foreach ($results['projects_processed'] as $projectKey) {
                    // Cache recent worklogs for quick access
                    $cacheService->warmProjectCache($projectKey, [
                        'recent_worklogs' => true,
                        'sync_date' => now()->toDateString()
                    ]);
                }
            }
            
            Log::debug('Daily sync cache warming completed', [
                'sync_id' => $this->syncId,
                'projects_cached' => count($results['projects_processed'] ?? [])
            ]);
            
        } catch (Exception $e) {
            // Don't fail the job if cache warming fails
            Log::warning('Daily sync cache warming failed', [
                'sync_id' => $this->syncId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Broadcast daily sync started notification.
     */
    private function broadcastDailySyncStarted(): void
    {
        ProcessJiraRealTimeNotification::dispatch([
            'type' => 'daily_sync_started',
            'sync_id' => $this->syncId,
            'scheduled_time' => now()->toISOString(),
            'projects_count' => count($this->syncOptions['project_keys'] ?? []),
            'sync_options' => $this->syncOptions
        ])->onQueue('jira-realtime');
    }

    /**
     * Broadcast daily sync completed notification.
     */
    private function broadcastDailySyncCompleted(array $results, float $durationMinutes): void
    {
        ProcessJiraRealTimeNotification::dispatch([
            'type' => 'daily_sync_completed',
            'sync_id' => $this->syncId,
            'duration_minutes' => $durationMinutes,
            'projects_synced' => $results['projects_processed'] ?? 0,
            'new_worklogs' => $results['new_worklogs_imported'] ?? 0,
            'updated_worklogs' => $results['updated_worklogs'] ?? 0,
            'total_hours' => $results['total_hours_imported'] ?? 0,
            'prd_compliant' => $durationMinutes <= 15
        ])->onQueue('jira-realtime');
    }

    /**
     * Get the tags for job monitoring.
     */
    public function tags(): array
    {
        return [
            'jira-sync',
            'daily',
            'automated',
            'sync:' . $this->syncId,
            'scheduled:' . ($this->isScheduled ? 'yes' : 'no'),
            'prd-daily'
        ];
    }
}