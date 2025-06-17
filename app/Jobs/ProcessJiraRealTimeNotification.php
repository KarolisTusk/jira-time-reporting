<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessJiraRealTimeNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10; // PRD: < 5 seconds update requirement
    public int $tries = 1; // Fast failure for real-time jobs
    public int $maxExceptions = 1;

    protected array $notificationData;

    /**
     * Create a new real-time notification job (PRD: < 5 second updates).
     */
    public function __construct(array $notificationData)
    {
        $this->notificationData = $notificationData;
        
        // Use real-time queue for immediate processing
        $this->onQueue('jira-realtime');
    }

    /**
     * Execute the real-time notification (PRD: WebSocket broadcasting).
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        Log::debug('Real-time JIRA notification processing', [
            'type' => $this->notificationData['type'],
            'sync_id' => $this->notificationData['sync_id'] ?? null,
            'user_id' => $this->notificationData['user_id'] ?? null,
            'prd_target' => '< 5 seconds delivery',
            'queue' => 'jira-realtime'
        ]);

        try {
            // Process based on notification type
            switch ($this->notificationData['type']) {
                case 'sync_completed':
                    $this->handleSyncCompleted();
                    break;
                    
                case 'sync_failed':
                    $this->handleSyncFailed();
                    break;
                    
                case 'sync_progress':
                    $this->handleSyncProgress();
                    break;
                    
                case 'daily_sync_started':
                    $this->handleDailySyncStarted();
                    break;
                    
                case 'daily_sync_completed':
                    $this->handleDailySyncCompleted();
                    break;
                    
                default:
                    Log::warning('Unknown notification type', [
                        'type' => $this->notificationData['type'],
                        'data' => $this->notificationData
                    ]);
            }
            
            $duration = microtime(true) - $startTime;
            $durationMs = round($duration * 1000, 2);
            
            // Validate against PRD real-time requirement (< 5 seconds)
            $prdCompliant = $durationMs <= 5000;
            
            Log::debug('Real-time notification processed', [
                'type' => $this->notificationData['type'],
                'duration_ms' => $durationMs,
                'prd_compliant' => $prdCompliant,
                'performance_note' => $prdCompliant ? 'Meets PRD real-time target' : 'Exceeds PRD target'
            ]);

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            Log::error('Real-time notification failed', [
                'type' => $this->notificationData['type'],
                'error' => $e->getMessage(),
                'duration_ms' => round($duration * 1000, 2),
                'data' => $this->notificationData
            ]);

            // Don't retry real-time notifications - they're time-sensitive
            throw $e;
        }
    }

    /**
     * Handle sync completion notification.
     */
    private function handleSyncCompleted(): void
    {
        // In a real implementation, this would broadcast via WebSocket/Pusher
        // For now, we'll prepare the notification data
        $broadcastData = [
            'event' => 'jira.sync.completed',
            'channel' => 'user.' . $this->notificationData['user_id'],
            'data' => [
                'sync_id' => $this->notificationData['sync_id'],
                'duration_minutes' => $this->notificationData['duration_minutes'],
                'prd_compliant' => $this->notificationData['prd_compliant'],
                'results' => $this->notificationData['results'],
                'message' => $this->notificationData['prd_compliant'] 
                    ? 'Sync completed within PRD target (< 30 minutes)'
                    : 'Sync completed but exceeded PRD target - optimization needed',
                'timestamp' => now()->toISOString()
            ]
        ];

        // TODO: Implement actual WebSocket broadcasting
        // broadcast(new JiraSyncCompleted($broadcastData))->toOthers();
        
        Log::info('Sync completion notification prepared for broadcast', $broadcastData);
    }

    /**
     * Handle sync failure notification.
     */
    private function handleSyncFailed(): void
    {
        $broadcastData = [
            'event' => 'jira.sync.failed',
            'channel' => 'user.' . $this->notificationData['user_id'],
            'data' => [
                'sync_id' => $this->notificationData['sync_id'],
                'error' => $this->notificationData['error'],
                'retry_available' => $this->notificationData['retry_available'],
                'message' => $this->notificationData['retry_available']
                    ? 'Sync failed but will retry automatically'
                    : 'Sync failed permanently - manual intervention required',
                'timestamp' => now()->toISOString()
            ]
        ];

        // TODO: Implement actual WebSocket broadcasting
        Log::info('Sync failure notification prepared for broadcast', $broadcastData);
    }

    /**
     * Handle sync progress notification.
     */
    private function handleSyncProgress(): void
    {
        $broadcastData = [
            'event' => 'jira.sync.progress',
            'channel' => 'user.' . $this->notificationData['user_id'],
            'data' => [
                'sync_id' => $this->notificationData['sync_id'],
                'progress_percentage' => $this->notificationData['progress_percentage'],
                'current_project' => $this->notificationData['current_project'] ?? null,
                'projects_completed' => $this->notificationData['projects_completed'] ?? 0,
                'total_projects' => $this->notificationData['total_projects'] ?? 0,
                'estimated_time_remaining' => $this->notificationData['estimated_time_remaining'] ?? null,
                'timestamp' => now()->toISOString()
            ]
        ];

        // TODO: Implement actual WebSocket broadcasting
        Log::debug('Sync progress notification prepared for broadcast', $broadcastData);
    }

    /**
     * Handle daily sync started notification.
     */
    private function handleDailySyncStarted(): void
    {
        $broadcastData = [
            'event' => 'jira.daily_sync.started',
            'channel' => 'system.notifications',
            'data' => [
                'sync_id' => $this->notificationData['sync_id'],
                'scheduled_time' => $this->notificationData['scheduled_time'] ?? now()->toISOString(),
                'projects_count' => $this->notificationData['projects_count'] ?? 0,
                'message' => 'Daily automated JIRA sync started',
                'timestamp' => now()->toISOString()
            ]
        ];

        // TODO: Implement actual WebSocket broadcasting
        Log::info('Daily sync started notification prepared for broadcast', $broadcastData);
    }

    /**
     * Handle daily sync completed notification.
     */
    private function handleDailySyncCompleted(): void
    {
        $broadcastData = [
            'event' => 'jira.daily_sync.completed',
            'channel' => 'system.notifications',
            'data' => [
                'sync_id' => $this->notificationData['sync_id'],
                'duration_minutes' => $this->notificationData['duration_minutes'],
                'projects_synced' => $this->notificationData['projects_synced'] ?? 0,
                'new_worklogs' => $this->notificationData['new_worklogs'] ?? 0,
                'updated_worklogs' => $this->notificationData['updated_worklogs'] ?? 0,
                'message' => 'Daily automated JIRA sync completed successfully',
                'timestamp' => now()->toISOString()
            ]
        ];

        // TODO: Implement actual WebSocket broadcasting
        Log::info('Daily sync completed notification prepared for broadcast', $broadcastData);
    }

    /**
     * Handle job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Real-time notification job failed permanently', [
            'type' => $this->notificationData['type'],
            'error' => $exception->getMessage(),
            'data' => $this->notificationData,
            'prd_impact' => 'Real-time updates unavailable - user experience degraded'
        ]);
    }

    /**
     * Get the tags for job monitoring.
     */
    public function tags(): array
    {
        return [
            'jira-notification',
            'real-time',
            'user:' . ($this->notificationData['user_id'] ?? 'system'),
            'type:' . $this->notificationData['type'],
            'prd-realtime'
        ];
    }
}