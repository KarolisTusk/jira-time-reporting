<?php

namespace App\Services;

use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncErrorService
{
    /**
     * Log detailed error information for a sync process.
     */
    public function logSyncError(
        JiraSyncHistory $syncHistory,
        Exception $exception,
        array $context = []
    ): void {
        $errorData = $this->analyzeException($exception);
        
        // Log to application log
        Log::error("Sync Error for Sync #{$syncHistory->id}", [
            'sync_id' => $syncHistory->id,
            'error_type' => $errorData['type'],
            'error_message' => $errorData['message'],
            'error_category' => $errorData['category'],
            'stack_trace' => $errorData['stack_trace'],
            'context' => $context,
            'sync_status' => $syncHistory->status,
            'progress' => $syncHistory->progress_percentage,
        ]);

        // Log to sync-specific log
        JiraSyncLog::create([
            'jira_sync_history_id' => $syncHistory->id,
            'timestamp' => now(),
            'level' => 'error',
            'message' => $errorData['friendly_message'],
            'context' => json_encode(array_merge($errorData, $context)),
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
        ]);

        // Update sync history with error count
        $syncHistory->increment('error_count');
        
        // Check if this is a critical error that should stop the sync
        if ($this->isCriticalError($errorData)) {
            $syncHistory->update([
                'status' => 'failed',
                'current_operation' => 'Failed due to critical error: ' . $errorData['friendly_message'],
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Analyze an exception and extract useful information.
     */
    public function analyzeException(Exception $exception): array
    {
        $errorType = get_class($exception);
        $message = $exception->getMessage();
        $category = $this->categorizeError($exception);
        
        return [
            'type' => $errorType,
            'message' => $message,
            'category' => $category,
            'friendly_message' => $this->getFriendlyMessage($exception),
            'severity' => $this->getErrorSeverity($exception),
            'is_retryable' => $this->isRetryableError($exception),
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'suggestions' => $this->getErrorSuggestions($exception),
        ];
    }

    /**
     * Categorize error types for better debugging.
     */
    protected function categorizeError(Exception $exception): string
    {
        $message = strtolower($exception->getMessage());
        $type = get_class($exception);

        // Network/API errors
        if (Str::contains($message, ['connection', 'timeout', 'network', 'curl', 'http'])) {
            return 'network';
        }

        // Database errors
        if (Str::contains($message, ['database', 'sql', 'connection', 'deadlock']) || 
            Str::contains($type, ['PDO', 'Database', 'Query'])) {
            return 'database';
        }

        // Memory errors
        if (Str::contains($message, ['memory', 'out of memory', 'allowed memory size'])) {
            return 'memory';
        }

        // JIRA API specific errors
        if (Str::contains($message, ['jira', 'api', 'unauthorized', '401', '403', '404', '500'])) {
            return 'jira_api';
        }

        // Permission errors
        if (Str::contains($message, ['permission', 'denied', 'unauthorized', 'forbidden'])) {
            return 'permission';
        }

        // Validation errors
        if (Str::contains($message, ['validation', 'invalid', 'required', 'format'])) {
            return 'validation';
        }

        // File system errors
        if (Str::contains($message, ['file', 'directory', 'disk', 'storage'])) {
            return 'filesystem';
        }

        return 'unknown';
    }

    /**
     * Get user-friendly error message.
     */
    protected function getFriendlyMessage(Exception $exception): string
    {
        $message = $exception->getMessage();
        $category = $this->categorizeError($exception);

        return match($category) {
            'network' => 'Network connection issue with JIRA API. Please check internet connection and JIRA server status.',
            'database' => 'Database connection or query issue. Please check database connectivity.',
            'memory' => 'Insufficient memory to complete the operation. Consider reducing batch size.',
            'jira_api' => 'JIRA API error: ' . $this->simplifyApiError($message),
            'permission' => 'Permission denied. Please check JIRA credentials and permissions.',
            'validation' => 'Data validation error: ' . $message,
            'filesystem' => 'File system error. Please check disk space and permissions.',
            default => $message
        };
    }

    /**
     * Simplify API error messages for better readability.
     */
    protected function simplifyApiError(string $message): string
    {
        if (Str::contains($message, '401')) {
            return 'Authentication failed. Please check JIRA credentials.';
        }

        if (Str::contains($message, '403')) {
            return 'Access forbidden. User may not have permission to access this data.';
        }

        if (Str::contains($message, '404')) {
            return 'Resource not found. Project or issue may not exist or be accessible.';
        }

        if (Str::contains($message, '429')) {
            return 'Rate limit exceeded. JIRA API calls are being throttled.';
        }

        if (Str::contains($message, '500')) {
            return 'JIRA server error. Please try again later or contact JIRA administrator.';
        }

        return $message;
    }

    /**
     * Determine error severity.
     */
    protected function getErrorSeverity(Exception $exception): string
    {
        $category = $this->categorizeError($exception);
        $message = strtolower($exception->getMessage());

        // Critical errors that should stop sync immediately
        if (in_array($category, ['memory', 'database']) || 
            Str::contains($message, ['fatal', 'critical', 'out of memory'])) {
            return 'critical';
        }

        // High severity errors
        if (in_array($category, ['permission', 'jira_api']) || 
            Str::contains($message, ['401', '403', 'unauthorized'])) {
            return 'high';
        }

        // Medium severity errors
        if (in_array($category, ['network', 'validation'])) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Determine if error is retryable.
     */
    protected function isRetryableError(Exception $exception): bool
    {
        $category = $this->categorizeError($exception);
        $message = strtolower($exception->getMessage());

        // Non-retryable errors
        if (in_array($category, ['permission', 'validation']) || 
            Str::contains($message, ['401', '403', 'unauthorized', 'forbidden', 'not found'])) {
            return false;
        }

        // Retryable errors
        if (in_array($category, ['network', 'jira_api']) || 
            Str::contains($message, ['timeout', '429', '500', '502', '503'])) {
            return true;
        }

        return true; // Default to retryable for unknown errors
    }

    /**
     * Check if error is critical enough to stop sync.
     */
    protected function isCriticalError(array $errorData): bool
    {
        return $errorData['severity'] === 'critical' || 
               !$errorData['is_retryable'];
    }

    /**
     * Get suggestions for resolving the error.
     */
    protected function getErrorSuggestions(Exception $exception): array
    {
        $category = $this->categorizeError($exception);
        $message = strtolower($exception->getMessage());

        $suggestions = [];

        switch ($category) {
            case 'network':
                $suggestions = [
                    'Check internet connectivity',
                    'Verify JIRA server URL is correct and accessible',
                    'Check firewall settings',
                    'Try again later if JIRA server is down'
                ];
                break;

            case 'jira_api':
                if (Str::contains($message, '401')) {
                    $suggestions = [
                        'Verify JIRA API token is correct and not expired',
                        'Check username/email is correct',
                        'Ensure API token has proper permissions'
                    ];
                } elseif (Str::contains($message, '403')) {
                    $suggestions = [
                        'User may not have permission to access the requested resource',
                        'Check JIRA project permissions',
                        'Contact JIRA administrator for access'
                    ];
                } elseif (Str::contains($message, '429')) {
                    $suggestions = [
                        'Reduce sync frequency',
                        'Decrease batch size in sync configuration',
                        'Wait for rate limit to reset'
                    ];
                } else {
                    $suggestions = [
                        'Check JIRA server status',
                        'Try sync again later',
                        'Contact JIRA administrator if problem persists'
                    ];
                }
                break;

            case 'memory':
                $suggestions = [
                    'Reduce batch size in sync configuration',
                    'Increase PHP memory limit',
                    'Process fewer projects at once',
                    'Consider running sync during off-peak hours'
                ];
                break;

            case 'database':
                $suggestions = [
                    'Check database connectivity',
                    'Verify database credentials',
                    'Check for database locks or deadlocks',
                    'Restart database connection if needed'
                ];
                break;

            default:
                $suggestions = [
                    'Check application logs for more details',
                    'Try the operation again',
                    'Contact system administrator if problem persists'
                ];
        }

        return $suggestions;
    }

    /**
     * Get error statistics for a sync.
     */
    public function getSyncErrorStats(JiraSyncHistory $syncHistory): array
    {
        $logs = JiraSyncLog::where('jira_sync_history_id', $syncHistory->id)
            ->where('level', 'error')
            ->get();

        $errorsByCategory = [];
        $errorsBySeverity = [];
        $retryableCount = 0;
        $criticalCount = 0;

        foreach ($logs as $log) {
            $context = json_decode($log->context, true) ?? [];
            
            $category = $context['category'] ?? 'unknown';
            $severity = $context['severity'] ?? 'medium';
            $isRetryable = $context['is_retryable'] ?? true;

            $errorsByCategory[$category] = ($errorsByCategory[$category] ?? 0) + 1;
            $errorsBySeverity[$severity] = ($errorsBySeverity[$severity] ?? 0) + 1;

            if ($isRetryable) {
                $retryableCount++;
            }

            if ($severity === 'critical') {
                $criticalCount++;
            }
        }

        return [
            'total_errors' => $logs->count(),
            'errors_by_category' => $errorsByCategory,
            'errors_by_severity' => $errorsBySeverity,
            'retryable_errors' => $retryableCount,
            'critical_errors' => $criticalCount,
            'recent_errors' => $logs->take(5)->map(function($log) {
                $context = json_decode($log->context, true) ?? [];
                return [
                    'time' => $log->created_at,
                    'message' => $log->message,
                    'category' => $context['category'] ?? 'unknown',
                    'severity' => $context['severity'] ?? 'medium',
                ];
            })->toArray()
        ];
    }

    /**
     * Generate error report for a sync.
     */
    public function generateErrorReport(JiraSyncHistory $syncHistory): array
    {
        $stats = $this->getSyncErrorStats($syncHistory);
        
        return [
            'sync_id' => $syncHistory->id,
            'sync_status' => $syncHistory->status,
            'progress' => $syncHistory->progress_percentage,
            'duration' => $syncHistory->started_at->diffForHumans(),
            'error_stats' => $stats,
            'recommendations' => $this->getRecommendations($stats),
            'next_steps' => $this->getNextSteps($syncHistory, $stats),
        ];
    }

    /**
     * Get recommendations based on error patterns.
     */
    protected function getRecommendations(array $stats): array
    {
        $recommendations = [];

        if ($stats['critical_errors'] > 0) {
            $recommendations[] = 'Address critical errors before retrying sync';
        }

        if (isset($stats['errors_by_category']['network']) && $stats['errors_by_category']['network'] > 3) {
            $recommendations[] = 'Check network connectivity and JIRA server stability';
        }

        if (isset($stats['errors_by_category']['memory']) && $stats['errors_by_category']['memory'] > 0) {
            $recommendations[] = 'Reduce batch size or increase memory limit';
        }

        if (isset($stats['errors_by_category']['jira_api']) && $stats['errors_by_category']['jira_api'] > 5) {
            $recommendations[] = 'Check JIRA API credentials and permissions';
        }

        if ($stats['total_errors'] > 10) {
            $recommendations[] = 'Consider running sync in smaller batches';
        }

        return $recommendations;
    }

    /**
     * Get next steps based on sync status and errors.
     */
    protected function getNextSteps(JiraSyncHistory $syncHistory, array $stats): array
    {
        $steps = [];

        if ($syncHistory->status === 'failed') {
            if ($stats['retryable_errors'] > $stats['total_errors'] / 2) {
                $steps[] = 'Retry sync after addressing network/temporary issues';
            } else {
                $steps[] = 'Review error details and fix configuration issues before retry';
            }
        }

        if ($syncHistory->status === 'in_progress' && $stats['critical_errors'] > 0) {
            $steps[] = 'Consider cancelling sync and fixing critical issues';
        }

        if ($stats['total_errors'] === 0) {
            $steps[] = 'No errors detected - sync should complete normally';
        }

        return $steps;
    }
} 