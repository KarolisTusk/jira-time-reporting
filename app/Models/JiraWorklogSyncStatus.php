<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraWorklogSyncStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_key',
        'last_sync_at',
        'last_sync_status',
        'worklogs_processed',
        'worklogs_added',
        'worklogs_updated',
        'last_error',
        'sync_metadata',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'sync_metadata' => 'array',
    ];

    /**
     * Check if the project needs an initial worklog sync.
     */
    public function needsInitialSync(): bool
    {
        return $this->last_sync_at === null || $this->last_sync_status === 'pending';
    }

    /**
     * Check if the project worklog sync has failed.
     */
    public function hasFailedSync(): bool
    {
        return in_array($this->last_sync_status, ['failed', 'completed_with_errors']);
    }

    /**
     * Check if the project worklog sync is currently in progress.
     */
    public function isSyncInProgress(): bool
    {
        return $this->last_sync_status === 'in_progress';
    }

    /**
     * Check if worklog sync is stale (hasn't run in 25+ hours).
     */
    public function isSyncStale(): bool
    {
        if (!$this->last_sync_at) {
            return true;
        }

        return $this->last_sync_at->lt(now()->subHours(25));
    }

    /**
     * Get time since last successful sync.
     */
    public function getTimeSinceLastSync(): ?string
    {
        if (!$this->last_sync_at) {
            return null;
        }

        return $this->last_sync_at->diffForHumans();
    }

    /**
     * Get sync success rate based on processed vs errors.
     */
    public function getSyncSuccessRate(): float
    {
        if ($this->worklogs_processed === 0) {
            return 100.0;
        }

        $successful = $this->worklogs_added + $this->worklogs_updated;
        return round(($successful / $this->worklogs_processed) * 100, 1);
    }

    /**
     * Mark sync as in progress.
     */
    public function markAsInProgress(): void
    {
        $this->update(['last_sync_status' => 'in_progress']);
    }

    /**
     * Mark sync as completed.
     */
    public function markAsCompleted(bool $hasErrors = false): void
    {
        $this->update([
            'last_sync_status' => $hasErrors ? 'completed_with_errors' : 'completed',
            'last_sync_at' => now(),
        ]);
    }

    /**
     * Mark sync as failed.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'last_sync_status' => 'failed',
            'last_error' => $error,
        ]);
    }

    /**
     * Scope for projects that need sync (stale or never synced).
     */
    public function scopeNeedingSync($query, int $staleHours = 25)
    {
        return $query->where(function ($q) use ($staleHours) {
            $q->whereNull('last_sync_at')
              ->orWhere('last_sync_at', '<', now()->subHours($staleHours))
              ->orWhere('last_sync_status', 'failed');
        });
    }

    /**
     * Scope for recently synced projects.
     */
    public function scopeRecentlySynced($query, int $hours = 24)
    {
        return $query->where('last_sync_at', '>=', now()->subHours($hours))
                     ->where('last_sync_status', 'completed');
    }
}