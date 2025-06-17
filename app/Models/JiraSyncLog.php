<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JiraSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_sync_history_id',
        'timestamp',
        'level',
        'message',
        'context',
        'entity_type',
        'entity_id',
        'operation',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'context' => 'array',
    ];

    /**
     * Get the sync history that owns the log.
     */
    public function syncHistory(): BelongsTo
    {
        return $this->belongsTo(JiraSyncHistory::class, 'jira_sync_history_id');
    }

    /**
     * Scope a query to only include logs with a specific level.
     */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    /**
     * Scope a query to only include info logs.
     */
    public function scopeInfo(Builder $query): Builder
    {
        return $query->where('level', 'info');
    }

    /**
     * Scope a query to only include warning logs.
     */
    public function scopeWarning(Builder $query): Builder
    {
        return $query->where('level', 'warning');
    }

    /**
     * Scope a query to only include error logs.
     */
    public function scopeError(Builder $query): Builder
    {
        return $query->where('level', 'error');
    }

    /**
     * Scope a query to filter by entity type.
     */
    public function scopeEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Scope a query to filter by operation.
     */
    public function scopeOperation(Builder $query, string $operation): Builder
    {
        return $query->where('operation', $operation);
    }

    /**
     * Scope a query to filter logs for a specific entity.
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * Scope a query to get logs with errors or warnings.
     */
    public function scopeProblematic(Builder $query): Builder
    {
        return $query->whereIn('level', ['warning', 'error']);
    }

    /**
     * Create a log entry for the sync history.
     */
    public static function log(
        int $syncHistoryId,
        string $level,
        string $message,
        array $context = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $operation = null
    ): self {
        return self::create([
            'jira_sync_history_id' => $syncHistoryId,
            'timestamp' => now(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
        ]);
    }

    /**
     * Create an info log entry.
     */
    public static function info(
        int $syncHistoryId,
        string $message,
        array $context = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $operation = null
    ): self {
        return self::log($syncHistoryId, 'info', $message, $context, $entityType, $entityId, $operation);
    }

    /**
     * Create a warning log entry.
     */
    public static function warning(
        int $syncHistoryId,
        string $message,
        array $context = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $operation = null
    ): self {
        return self::log($syncHistoryId, 'warning', $message, $context, $entityType, $entityId, $operation);
    }

    /**
     * Create an error log entry.
     */
    public static function error(
        int $syncHistoryId,
        string $message,
        array $context = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $operation = null
    ): self {
        return self::log($syncHistoryId, 'error', $message, $context, $entityType, $entityId, $operation);
    }

    /**
     * Get a formatted timestamp for display.
     */
    public function getFormattedTimestampAttribute(): string
    {
        return $this->timestamp->format('Y-m-d H:i:s');
    }

    /**
     * Get a human-readable timestamp.
     */
    public function getHumanTimestampAttribute(): string
    {
        return $this->timestamp->diffForHumans();
    }

    /**
     * Get the log level badge color for UI display.
     */
    public function getLevelColorAttribute(): string
    {
        return match ($this->level) {
            'info' => 'blue',
            'warning' => 'yellow',
            'error' => 'red',
            default => 'gray',
        };
    }

    /**
     * Check if the log is an error.
     */
    public function getIsErrorAttribute(): bool
    {
        return $this->level === 'error';
    }

    /**
     * Check if the log is a warning.
     */
    public function getIsWarningAttribute(): bool
    {
        return $this->level === 'warning';
    }

    /**
     * Check if the log is info.
     */
    public function getIsInfoAttribute(): bool
    {
        return $this->level === 'info';
    }
}
