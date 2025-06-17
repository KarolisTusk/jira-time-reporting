<?php

namespace App\Services;

use App\Events\JiraSyncProgress;
use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;

class JiraSyncProgressService
{
    protected ?JiraSyncHistory $syncHistory = null;

    protected array $progressData = [];

    /**
     * Set the sync history for tracking progress.
     */
    public function setSyncHistory(JiraSyncHistory $syncHistory): void
    {
        $this->syncHistory = $syncHistory;
        $this->initializeProgress();
    }

    /**
     * Initialize progress tracking data.
     */
    protected function initializeProgress(): void
    {
        $this->progressData = [
            'current_operation' => 'Initializing',
            'projects' => [
                'total' => 0,
                'processed' => 0,
                'current' => null,
            ],
            'issues' => [
                'total' => 0,
                'processed' => 0,
                'current_project' => null,
            ],
            'worklogs' => [
                'total' => 0,
                'processed' => 0,
                'current_issue' => null,
            ],
            'users' => [
                'total' => 0,
                'processed' => 0,
            ],
            'start_time' => now(),
            'estimated_completion' => null,
        ];
    }

    /**
     * Update the current operation being performed.
     */
    public function setCurrentOperation(string $operation): void
    {
        $this->progressData['current_operation'] = $operation;
        $this->broadcastProgressInternal();

        if ($this->syncHistory) {
            JiraSyncLog::info(
                $this->syncHistory->id,
                "Operation: {$operation}"
            );
        }
    }

    /**
     * Update the current operation and progress (alias for compatibility).
     */
    public function updateCurrentOperation(string $operation, ?int $progressPercentage = null): void
    {
        $this->progressData['current_operation'] = $operation;
        
        if ($this->syncHistory) {
            $updateData = ['current_operation' => $operation];
            
            if ($progressPercentage !== null) {
                $updateData['progress_percentage'] = $progressPercentage;
            }
            
            $this->syncHistory->update($updateData);
            
            JiraSyncLog::info(
                $this->syncHistory->id,
                "Operation: {$operation}"
            );
        }
        
        $this->broadcastProgressInternal();
    }

    /**
     * Set project totals.
     */
    public function setProjectTotals(int $total): void
    {
        $this->progressData['projects']['total'] = $total;

        if ($this->syncHistory) {
            $this->syncHistory->update(['total_projects' => $total]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Update project progress.
     */
    public function updateProjectProgress(int $processed, ?string $currentProject = null): void
    {
        $this->progressData['projects']['processed'] = $processed;
        $this->progressData['projects']['current'] = $currentProject;

        if ($this->syncHistory) {
            $this->syncHistory->update(['processed_projects' => $processed]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Set issue totals for current project.
     */
    public function setIssueTotals(int $total, ?string $projectKey = null): void
    {
        $this->progressData['issues']['total'] = $total;
        $this->progressData['issues']['current_project'] = $projectKey;

        if ($this->syncHistory) {
            $this->syncHistory->update(['total_issues' => $total]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Update issue progress.
     */
    public function updateIssueProgress(int $processed): void
    {
        $this->progressData['issues']['processed'] = $processed;

        if ($this->syncHistory) {
            $this->syncHistory->update(['processed_issues' => $processed]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Set worklog totals.
     */
    public function setWorklogTotals(int $total): void
    {
        $this->progressData['worklogs']['total'] = $total;

        if ($this->syncHistory) {
            $this->syncHistory->update(['total_worklogs' => $total]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Update worklog progress.
     */
    public function updateWorklogProgress(int $processed, ?string $currentIssue = null): void
    {
        $this->progressData['worklogs']['processed'] = $processed;
        $this->progressData['worklogs']['current_issue'] = $currentIssue;

        if ($this->syncHistory) {
            $this->syncHistory->update(['processed_worklogs' => $processed]);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Update user progress.
     */
    public function updateUserProgress(int $processed, ?int $total = null): void
    {
        $this->progressData['users']['processed'] = $processed;

        if ($total !== null) {
            $this->progressData['users']['total'] = $total;
        }

        if ($this->syncHistory) {
            $updates = ['processed_users' => $processed];
            if ($total !== null) {
                $updates['total_users'] = $total;
            }
            $this->syncHistory->update($updates);
        }

        $this->broadcastProgressInternal();
    }

    /**
     * Calculate estimated completion time.
     */
    protected function calculateEstimatedCompletion(): ?string
    {
        $totalItems = $this->progressData['projects']['total'] +
                     $this->progressData['issues']['total'] +
                     $this->progressData['worklogs']['total'];

        $processedItems = $this->progressData['projects']['processed'] +
                         $this->progressData['issues']['processed'] +
                         $this->progressData['worklogs']['processed'];

        if ($totalItems === 0 || $processedItems === 0) {
            return null;
        }

        $elapsedSeconds = now()->diffInSeconds($this->progressData['start_time']);
        $itemsPerSecond = $processedItems / $elapsedSeconds;
        $remainingItems = $totalItems - $processedItems;

        if ($itemsPerSecond <= 0) {
            return null;
        }

        $estimatedSecondsRemaining = $remainingItems / $itemsPerSecond;

        return now()->addSeconds($estimatedSecondsRemaining)->toIso8601String();
    }

    /**
     * Get the current progress data.
     */
    public function getProgressData(): array
    {
        $this->progressData['estimated_completion'] = $this->calculateEstimatedCompletion();
        $this->progressData['elapsed_time'] = now()->diffInSeconds($this->progressData['start_time']);

        return $this->progressData;
    }

    /**
     * Broadcast progress update with optional message.
     */
    public function broadcastProgress(JiraSyncHistory $syncHistory, ?string $message = null): void
    {
        // Set or update the sync history
        $this->setSyncHistory($syncHistory);
        
        // Update current operation if message provided
        if ($message) {
            $this->setCurrentOperation($message);
        }
        
        // Refresh the sync history to get updated data
        $this->syncHistory->refresh();

        broadcast(new JiraSyncProgress($this->syncHistory, $this->getProgressData()))->toOthers();
    }

    /**
     * Internal broadcast method for existing class usage.
     */
    protected function broadcastProgressInternal(): void
    {
        if ($this->syncHistory) {
            // Refresh the sync history to get updated data
            $this->syncHistory->refresh();

            broadcast(new JiraSyncProgress($this->syncHistory, $this->getProgressData()))->toOthers();
        }
    }

    /**
     * Log an error and update error count.
     */
    public function logError(string $message, array $context = [], ?string $entityType = null, ?string $entityId = null): void
    {
        if ($this->syncHistory) {
            JiraSyncLog::error(
                $this->syncHistory->id,
                $message,
                $context,
                $entityType,
                $entityId
            );

            $this->syncHistory->increment('error_count');
            $this->syncHistory->addError($message, $context);
        }
    }

    /**
     * Log a warning.
     */
    public function logWarning(string $message, array $context = [], ?string $entityType = null, ?string $entityId = null): void
    {
        if ($this->syncHistory) {
            JiraSyncLog::warning(
                $this->syncHistory->id,
                $message,
                $context,
                $entityType,
                $entityId
            );
        }
    }

    /**
     * Log an info message.
     */
    public function logInfo(string $message, array $context = [], ?string $entityType = null, ?string $entityId = null, ?string $operation = null): void
    {
        if ($this->syncHistory) {
            JiraSyncLog::info(
                $this->syncHistory->id,
                $message,
                $context,
                $entityType,
                $entityId,
                $operation
            );
        }
    }
}
