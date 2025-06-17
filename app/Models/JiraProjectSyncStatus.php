<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraProjectSyncStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_key',
        'last_sync_at',
        'last_sync_status',
        'issues_count',
        'last_error',
        'sync_metadata',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'sync_metadata' => 'array',
    ];

    /**
     * Check if the project needs an initial sync.
     */
    public function needsInitialSync(): bool
    {
        return $this->last_sync_at === null || $this->last_sync_status === 'pending';
    }

    /**
     * Check if the project has failed its last sync.
     */
    public function hasFailedSync(): bool
    {
        return $this->last_sync_status === 'failed';
    }

    /**
     * Check if the project sync is currently in progress.
     */
    public function isSyncInProgress(): bool
    {
        return $this->last_sync_status === 'in_progress';
    }

    /**
     * Check if the project was successfully synced.
     */
    public function hasSuccessfulSync(): bool
    {
        return $this->last_sync_status === 'completed' && $this->last_sync_at !== null;
    }

    /**
     * Get the time since last successful sync.
     */
    public function getTimeSinceLastSyncAttribute(): ?string
    {
        if (!$this->last_sync_at || !$this->hasSuccessfulSync()) {
            return null;
        }

        return $this->last_sync_at->diffForHumans();
    }

    /**
     * Check if the project is due for incremental sync based on age.
     */
    public function isDueForSync(int $hoursThreshold = 24): bool
    {
        if (!$this->last_sync_at) {
            return true; // Never synced
        }

        if ($this->last_sync_status !== 'completed') {
            return true; // Last sync was not successful
        }

        return $this->last_sync_at->addHours($hoursThreshold)->isPast();
    }

    /**
     * Mark the sync as started.
     */
    public function markSyncStarted(): void
    {
        $this->update([
            'last_sync_status' => 'in_progress',
            'last_error' => null,
        ]);
    }

    /**
     * Mark the sync as completed successfully.
     */
    public function markSyncCompleted(int $issuesCount = null, array $metadata = []): void
    {
        $updates = [
            'last_sync_at' => now(),
            'last_sync_status' => 'completed',
            'last_error' => null,
        ];

        if ($issuesCount !== null) {
            $updates['issues_count'] = $issuesCount;
        }

        if (!empty($metadata)) {
            $updates['sync_metadata'] = array_merge($this->sync_metadata ?? [], $metadata);
        }

        $this->update($updates);
    }

    /**
     * Mark the sync as failed.
     */
    public function markSyncFailed(string $error, array $metadata = []): void
    {
        $updates = [
            'last_sync_status' => 'failed',
            'last_error' => $error,
        ];

        if (!empty($metadata)) {
            $updates['sync_metadata'] = array_merge($this->sync_metadata ?? [], [
                'failed_at' => now()->toISOString(),
                'error_metadata' => $metadata,
            ]);
        }

        $this->update($updates);
    }

    /**
     * Get the last sync date range from metadata.
     */
    public function getLastSyncDateRange(): ?array
    {
        $metadata = $this->sync_metadata ?? [];
        
        if (isset($metadata['date_range'])) {
            return $metadata['date_range'];
        }

        return null;
    }

    /**
     * Set sync metadata for the current operation.
     */
    public function setSyncMetadata(array $metadata): void
    {
        $this->update([
            'sync_metadata' => array_merge($this->sync_metadata ?? [], $metadata),
        ]);
    }

    /**
     * Scope to filter projects that need sync.
     */
    public function scopeNeedingSync($query, int $hoursThreshold = 24)
    {
        return $query->where(function ($q) use ($hoursThreshold) {
            $q->whereNull('last_sync_at')
              ->orWhere('last_sync_status', '!=', 'completed')
              ->orWhere('last_sync_at', '<', Carbon::now()->subHours($hoursThreshold));
        });
    }

    /**
     * Scope to filter projects with failed syncs.
     */
    public function scopeWithFailedSync($query)
    {
        return $query->where('last_sync_status', 'failed');
    }

    /**
     * Scope to filter projects currently syncing.
     */
    public function scopeCurrentlySyncing($query)
    {
        return $query->where('last_sync_status', 'in_progress');
    }

    /**
     * Get a summary of sync statistics.
     */
    public static function getSyncSummary(): array
    {
        $total = static::count();
        $completed = static::where('last_sync_status', 'completed')->count();
        $failed = static::where('last_sync_status', 'failed')->count();
        $inProgress = static::where('last_sync_status', 'in_progress')->count();
        $pending = static::where('last_sync_status', 'pending')->count();

        return [
            'total_projects' => $total,
            'completed_syncs' => $completed,
            'failed_syncs' => $failed,
            'in_progress_syncs' => $inProgress,
            'pending_syncs' => $pending,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }
}