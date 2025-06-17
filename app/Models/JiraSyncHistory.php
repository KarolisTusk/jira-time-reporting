<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class JiraSyncHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'total_projects',
        'processed_projects',
        'total_issues',
        'processed_issues',
        'total_worklogs',
        'processed_worklogs',
        'total_users',
        'processed_users',
        'error_count',
        'error_details',
        'duration_seconds',
        'triggered_by',
        'sync_type',
        'progress_percentage',
        'current_operation',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'error_details' => 'array',
    ];

    /**
     * Get the user who triggered the sync.
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Get the sync logs for this sync history.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(JiraSyncLog::class);
    }

    /**
     * Get the sync checkpoints for this sync history.
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(JiraSyncCheckpoint::class, 'jira_sync_history_id');
    }

    /**
     * Scope a query to only include syncs with a specific status.
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending syncs.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include in-progress syncs.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope a query to only include completed syncs.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include failed syncs.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange(Builder $query, ?Carbon $startDate, ?Carbon $endDate): Builder
    {
        if ($startDate) {
            $query->where('started_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('started_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Scope a query to filter by user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('triggered_by', $userId);
    }

    /**
     * Calculate the overall progress percentage.
     */
    public function getProgressPercentageAttribute(): float
    {
        $totalItems = $this->total_projects + $this->total_issues + $this->total_worklogs + $this->total_users;
        $processedItems = $this->processed_projects + $this->processed_issues + $this->processed_worklogs + $this->processed_users;

        if ($totalItems === 0) {
            return 0;
        }

        return round(($processedItems / $totalItems) * 100, 2);
    }

    /**
     * Calculate the project progress percentage.
     */
    public function getProjectProgressPercentageAttribute(): float
    {
        if ($this->total_projects === 0) {
            return 0;
        }

        return round(($this->processed_projects / $this->total_projects) * 100, 2);
    }

    /**
     * Calculate the issue progress percentage.
     */
    public function getIssueProgressPercentageAttribute(): float
    {
        if ($this->total_issues === 0) {
            return 0;
        }

        return round(($this->processed_issues / $this->total_issues) * 100, 2);
    }

    /**
     * Calculate the worklog progress percentage.
     */
    public function getWorklogProgressPercentageAttribute(): float
    {
        if ($this->total_worklogs === 0) {
            return 0;
        }

        return round(($this->processed_worklogs / $this->total_worklogs) * 100, 2);
    }

    /**
     * Calculate the user progress percentage.
     */
    public function getUserProgressPercentageAttribute(): float
    {
        if ($this->total_users === 0) {
            return 0;
        }

        return round(($this->processed_users / $this->total_users) * 100, 2);
    }

    /**
     * Check if the sync is still running.
     */
    public function getIsRunningAttribute(): bool
    {
        return in_array($this->status, ['pending', 'in_progress']);
    }

    /**
     * Check if the sync has errors.
     */
    public function getHasErrorsAttribute(): bool
    {
        return $this->error_count > 0;
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (! $this->duration_seconds) {
            return 'N/A';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    /**
     * Update progress for a specific entity type.
     */
    public function updateProgress(string $entityType, int $processed, ?int $total = null): void
    {
        $processedField = "processed_{$entityType}";
        $totalField = "total_{$entityType}";

        $updates = [$processedField => $processed];

        if ($total !== null) {
            $updates[$totalField] = $total;
        }

        $this->update($updates);
    }

    /**
     * Mark the sync as started.
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'progress_percentage' => 0,
            'current_operation' => 'Starting sync process...',
        ]);
        
        // Log status change
        Log::info("Sync {$this->id} marked as started", [
            'sync_id' => $this->id,
            'triggered_by' => $this->triggered_by,
            'project_keys' => $this->project_keys ?? [],
        ]);
    }

    /**
     * Mark the sync as completed.
     */
    public function markAsCompleted(): void
    {
        $completedAt = now();
        $duration = 0;
        
        if ($this->started_at) {
            // Ensure duration is never negative and convert to integer
            $calculatedDuration = $completedAt->diffInSeconds($this->started_at);
            $duration = max(0, (int) round($calculatedDuration));
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
            'progress_percentage' => 100,
            'current_operation' => 'Sync completed successfully',
        ]);
        
        // Log completion
        Log::info("Sync {$this->id} completed successfully", [
            'sync_id' => $this->id,
            'duration_seconds' => $duration,
            'total_projects' => $this->total_projects,
            'total_issues' => $this->total_issues,
            'total_worklogs' => $this->total_worklogs,
        ]);
    }

    /**
     * Mark the sync as failed.
     */
    public function markAsFailed(array $errorDetails = []): void
    {
        $completedAt = now();
        $duration = 0;
        
        if ($this->started_at) {
            // Ensure duration is never negative and convert to integer
            $calculatedDuration = $completedAt->diffInSeconds($this->started_at);
            $duration = max(0, (int) round($calculatedDuration));
        }

        $this->update([
            'status' => 'failed',
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
            'current_operation' => 'Sync failed',
            'error_details' => array_merge($this->error_details ?? [], $errorDetails),
        ]);
        
        // Log failure
        Log::error("Sync {$this->id} failed", [
            'sync_id' => $this->id,
            'duration_seconds' => $duration,
            'error_details' => $errorDetails,
            'error_count' => $this->error_count,
        ]);
    }

    /**
     * Update current operation and progress.
     */
    public function updateCurrentOperation(string $operation, ?int $progressPercentage = null): void
    {
        $updates = ['current_operation' => $operation];
        
        if ($progressPercentage !== null) {
            $updates['progress_percentage'] = max(0, min(100, $progressPercentage));
        }
        
        $this->update($updates);
    }

    /**
     * Check if the sync is stale (started but not updated recently).
     */
    public function getIsStaleAttribute(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }
        
        // Consider sync stale if it's been in progress for more than 2 hours without updates
        return $this->updated_at && $this->updated_at->diffInHours(now()) > 2;
    }

    /**
     * Get estimated completion time based on current progress.
     */
    public function getEstimatedCompletionAttribute(): ?Carbon
    {
        if ($this->status !== 'in_progress' || !$this->started_at || $this->progress_percentage <= 0) {
            return null;
        }
        
        $elapsedSeconds = $this->started_at->diffInSeconds(now());
        $progressRatio = $this->progress_percentage / 100;
        $estimatedTotalSeconds = $elapsedSeconds / $progressRatio;
        $remainingSeconds = $estimatedTotalSeconds - $elapsedSeconds;
        
        return now()->addSeconds($remainingSeconds);
    }

    /**
     * Add an error to the sync.
     */
    public function addError(string $message, array $context = []): void
    {
        $errorDetails = $this->error_details ?? [];
        $errorDetails[] = [
            'message' => $message,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->update([
            'error_count' => $this->error_count + 1,
            'error_details' => $errorDetails,
        ]);
    }

    /**
     * Check if the sync can be retried.
     */
    public function getCanRetryAttribute(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the sync can be cancelled.
     */
    public function getCanCancelAttribute(): bool
    {
        return in_array($this->status, ['pending', 'in_progress']);
    }

    /**
     * Get recently synced issues for this sync operation.
     */
    public function getRecentlySyncedIssues(int $limit = 10): array
    {
        // Get recent sync logs for this sync that mention issue processing
        $recentLogs = JiraSyncLog::where('jira_sync_history_id', $this->id)
            ->where('level', 'info')
            ->where(function ($query) {
                $query->where('message', 'like', '%Processing issue:%')
                      ->orWhere('message', 'like', '%Successfully processed issue%')
                      ->orWhere('message', 'like', '%Processing worklogs for issue:%');
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit * 3) // Get more to filter unique issues
            ->get();

        $processedIssues = [];
        $issueKeys = [];

        foreach ($recentLogs as $log) {
            // Extract issue key from log message
            if (preg_match('/(?:Processing issue:|Processing worklogs for issue:|Successfully processed issue)\s*([A-Z]+-\d+)/', $log->message, $matches)) {
                $issueKey = $matches[1];
                
                if (!in_array($issueKey, $issueKeys)) {
                    $issueKeys[] = $issueKey;
                    $processedIssues[] = [
                        'key' => $issueKey,
                        'processed_at' => $log->created_at,
                        'operation' => $this->extractOperationType($log->message),
                    ];
                    
                    if (count($processedIssues) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $processedIssues;
    }

    /**
     * Extract operation type from log message.
     */
    private function extractOperationType(string $message): string
    {
        if (strpos($message, 'Processing worklogs') !== false) {
            return 'worklogs';
        } elseif (strpos($message, 'Successfully processed') !== false) {
            return 'completed';
        } elseif (strpos($message, 'Processing issue') !== false) {
            return 'processing';
        }
        
        return 'unknown';
    }

    /**
     * Get current sync statistics.
     */
    public function getCurrentStats(): array
    {
        return [
            'progress_percentage' => $this->progress_percentage,
            'current_operation' => $this->current_operation,
            'projects' => [
                'total' => $this->total_projects,
                'processed' => $this->processed_projects,
                'percentage' => $this->total_projects > 0 ? round(($this->processed_projects / $this->total_projects) * 100, 1) : 0,
            ],
            'issues' => [
                'total' => $this->total_issues,
                'processed' => $this->processed_issues,
                'percentage' => $this->total_issues > 0 ? round(($this->processed_issues / $this->total_issues) * 100, 1) : 0,
            ],
            'worklogs' => [
                'total' => $this->total_worklogs,
                'processed' => $this->processed_worklogs,
                'percentage' => $this->total_worklogs > 0 ? round(($this->processed_worklogs / $this->total_worklogs) * 100, 1) : 0,
            ],
            'users' => [
                'total' => $this->total_users,
                'processed' => $this->processed_users,
                'percentage' => $this->total_users > 0 ? round(($this->processed_users / $this->total_users) * 100, 1) : 0,
            ],
            'errors' => $this->error_count,
            'duration' => $this->started_at ? $this->started_at->diffForHumans(null, true) : null,
            'estimated_completion' => $this->getEstimatedCompletion(),
        ];
    }

    /**
     * Get estimated completion time.
     */
    public function getEstimatedCompletion(): ?string
    {
        if ($this->status !== 'in_progress' || !$this->started_at) {
            return null;
        }

        $totalItems = $this->total_projects + $this->total_issues + $this->total_worklogs;
        $processedItems = $this->processed_projects + $this->processed_issues + $this->processed_worklogs;

        if ($totalItems === 0 || $processedItems === 0) {
            return null;
        }

        $elapsedMinutes = $this->started_at->diffInMinutes(now());
        if ($elapsedMinutes === 0) {
            return null;
        }

        $itemsPerMinute = $processedItems / $elapsedMinutes;
        $remainingItems = $totalItems - $processedItems;

        if ($itemsPerMinute <= 0) {
            return null;
        }

        $estimatedMinutesRemaining = $remainingItems / $itemsPerMinute;
        
        return now()->addMinutes($estimatedMinutesRemaining)->diffForHumans();
    }
}
