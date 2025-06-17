<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JiraSyncCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_sync_history_id',
        'project_key',
        'checkpoint_type',
        'checkpoint_data',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'checkpoint_data' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the sync history that owns this checkpoint.
     */
    public function syncHistory(): BelongsTo
    {
        return $this->belongsTo(JiraSyncHistory::class, 'jira_sync_history_id');
    }

    /**
     * Check if the checkpoint is completed.
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the checkpoint has failed.
     */
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the checkpoint is still active.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the error details if the checkpoint failed.
     */
    public function getErrorDetailsAttribute(): ?array
    {
        return $this->checkpoint_data['error'] ?? null;
    }

    /**
     * Get the progress percentage from checkpoint data.
     */
    public function getProgressPercentageAttribute(): float
    {
        $data = $this->checkpoint_data ?? [];
        
        if (isset($data['issues_processed']) && isset($data['total_issues']) && $data['total_issues'] > 0) {
            return round(($data['issues_processed'] / $data['total_issues']) * 100, 2);
        }
        
        return 0;
    }

    /**
     * Scope to filter by checkpoint type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('checkpoint_type', $type);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by project key.
     */
    public function scopeForProject($query, string $projectKey)
    {
        return $query->where('project_key', $projectKey);
    }
}