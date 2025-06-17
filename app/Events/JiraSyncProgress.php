<?php

namespace App\Events;

use App\Models\JiraSyncHistory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JiraSyncProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public JiraSyncHistory $syncHistory;

    public array $progressData;

    /**
     * Create a new event instance.
     */
    public function __construct(JiraSyncHistory $syncHistory, array $progressData = [])
    {
        $this->syncHistory = $syncHistory;
        $this->progressData = $progressData;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('jira-sync.'.$this->syncHistory->triggered_by),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'sync_history_id' => $this->syncHistory->id,
            'status' => $this->syncHistory->status,
            'progress_percentage' => $this->syncHistory->progress_percentage,
            'project_progress_percentage' => $this->syncHistory->project_progress_percentage,
            'issue_progress_percentage' => $this->syncHistory->issue_progress_percentage,
            'worklog_progress_percentage' => $this->syncHistory->worklog_progress_percentage,
            'user_progress_percentage' => $this->syncHistory->user_progress_percentage,
            'totals' => [
                'projects' => $this->syncHistory->total_projects,
                'issues' => $this->syncHistory->total_issues,
                'worklogs' => $this->syncHistory->total_worklogs,
                'users' => $this->syncHistory->total_users,
            ],
            'processed' => [
                'projects' => $this->syncHistory->processed_projects,
                'issues' => $this->syncHistory->processed_issues,
                'worklogs' => $this->syncHistory->processed_worklogs,
                'users' => $this->syncHistory->processed_users,
            ],
            'error_count' => $this->syncHistory->error_count,
            'has_errors' => $this->syncHistory->has_errors,
            'is_running' => $this->syncHistory->is_running,
            'started_at' => $this->syncHistory->started_at?->toIso8601String(),
            'completed_at' => $this->syncHistory->completed_at?->toIso8601String(),
            'formatted_duration' => $this->syncHistory->formatted_duration,
            'progress_data' => $this->progressData,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'jira.sync.progress';
    }

    /**
     * Determine if this event should broadcast.
     */
    public function shouldBroadcast(): bool
    {
        // Only broadcast if the sync history exists and belongs to a user
        return $this->syncHistory->exists && $this->syncHistory->triggered_by;
    }
}
