<?php

namespace App\Services;

use App\Models\JiraSyncCheckpoint;
use App\Models\JiraSyncHistory;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JiraSyncCheckpointService
{
    /**
     * Create a new checkpoint for a sync operation.
     */
    public function createCheckpoint(int $syncHistoryId, string $projectKey, array $data = []): JiraSyncCheckpoint
    {
        $checkpoint = JiraSyncCheckpoint::create([
            'jira_sync_history_id' => $syncHistoryId,
            'project_key' => $projectKey,
            'checkpoint_type' => 'project_sync',
            'checkpoint_data' => $data,
            'status' => 'active',
            'created_at' => now(),
        ]);

        Log::info("Checkpoint created for sync {$syncHistoryId}, project {$projectKey}", [
            'checkpoint_id' => $checkpoint->id,
            'data' => $data,
        ]);

        return $checkpoint;
    }

    /**
     * Update an existing checkpoint with new data.
     */
    public function updateCheckpoint(int $checkpointId, array $data): bool
    {
        try {
            $checkpoint = JiraSyncCheckpoint::findOrFail($checkpointId);
            
            $mergedData = array_merge($checkpoint->checkpoint_data ?? [], $data);
            $checkpoint->update([
                'checkpoint_data' => $mergedData,
                'updated_at' => now(),
            ]);

            Log::debug("Checkpoint {$checkpointId} updated", ['data' => $data]);
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to update checkpoint {$checkpointId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a checkpoint as completed.
     */
    public function completeCheckpoint(int $checkpointId): bool
    {
        try {
            $checkpoint = JiraSyncCheckpoint::findOrFail($checkpointId);
            $checkpoint->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info("Checkpoint {$checkpointId} marked as completed");
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to complete checkpoint {$checkpointId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a checkpoint as failed with error details.
     */
    public function failCheckpoint(int $checkpointId, string $errorMessage, array $errorContext = []): bool
    {
        try {
            $checkpoint = JiraSyncCheckpoint::findOrFail($checkpointId);
            
            $errorData = [
                'error_message' => $errorMessage,
                'error_context' => $errorContext,
                'failed_at' => now()->toISOString(),
            ];
            
            $checkpointData = $checkpoint->checkpoint_data ?? [];
            $checkpointData['error'] = $errorData;
            
            $checkpoint->update([
                'status' => 'failed',
                'checkpoint_data' => $checkpointData,
                'completed_at' => now(),
            ]);

            Log::error("Checkpoint {$checkpointId} marked as failed", [
                'error' => $errorMessage,
                'context' => $errorContext,
            ]);
            
            return true;
        } catch (Exception $e) {
            Log::error("Failed to fail checkpoint {$checkpointId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all checkpoints for a sync history.
     */
    public function getCheckpointsForSync(int $syncHistoryId): Collection
    {
        return JiraSyncCheckpoint::where('jira_sync_history_id', $syncHistoryId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get the latest checkpoint for a specific project in a sync.
     */
    public function getLatestCheckpointForProject(int $syncHistoryId, string $projectKey): ?JiraSyncCheckpoint
    {
        return JiraSyncCheckpoint::where('jira_sync_history_id', $syncHistoryId)
            ->where('project_key', $projectKey)
            ->latest('created_at')
            ->first();
    }

    /**
     * Resume a failed sync from the last successful checkpoint.
     */
    public function resumeFromCheckpoint(int $syncHistoryId): array
    {
        Log::info("Attempting to resume sync {$syncHistoryId} from checkpoint");
        
        $syncHistory = JiraSyncHistory::findOrFail($syncHistoryId);
        $checkpoints = $this->getCheckpointsForSync($syncHistoryId);
        
        $resumeData = [
            'can_resume' => false,
            'projects_to_retry' => [],
            'completed_projects' => [],
            'failed_projects' => [],
            'resume_strategy' => null,
        ];
        
        if ($checkpoints->isEmpty()) {
            $resumeData['resume_strategy'] = 'full_restart';
            $resumeData['can_resume'] = true;
            Log::info("No checkpoints found, recommending full restart");
            return $resumeData;
        }
        
        // Analyze checkpoint status by project
        $projectStatus = [];
        foreach ($checkpoints as $checkpoint) {
            $projectKey = $checkpoint->project_key;
            
            if (!isset($projectStatus[$projectKey])) {
                $projectStatus[$projectKey] = [
                    'status' => $checkpoint->status,
                    'checkpoint' => $checkpoint,
                    'data' => $checkpoint->checkpoint_data ?? [],
                ];
            }
            
            // Keep the latest status for each project
            if ($checkpoint->created_at > $projectStatus[$projectKey]['checkpoint']->created_at) {
                $projectStatus[$projectKey]['status'] = $checkpoint->status;
                $projectStatus[$projectKey]['checkpoint'] = $checkpoint;
                $projectStatus[$projectKey]['data'] = $checkpoint->checkpoint_data ?? [];
            }
        }
        
        // Categorize projects by status
        foreach ($projectStatus as $projectKey => $status) {
            switch ($status['status']) {
                case 'completed':
                    $resumeData['completed_projects'][] = $projectKey;
                    break;
                case 'failed':
                    $resumeData['failed_projects'][] = $projectKey;
                    $resumeData['projects_to_retry'][] = [
                        'project_key' => $projectKey,
                        'last_checkpoint' => $status['data'],
                        'failure_reason' => $status['data']['error'] ?? 'Unknown error',
                    ];
                    break;
                case 'active':
                    // Active checkpoints indicate incomplete work
                    $resumeData['projects_to_retry'][] = [
                        'project_key' => $projectKey,
                        'last_checkpoint' => $status['data'],
                        'resume_from' => $this->determineResumePoint($status['data']),
                    ];
                    break;
            }
        }
        
        // Determine resume strategy
        if (!empty($resumeData['projects_to_retry'])) {
            $resumeData['can_resume'] = true;
            $resumeData['resume_strategy'] = 'partial_resume';
        } elseif (!empty($resumeData['completed_projects']) && empty($resumeData['failed_projects'])) {
            $resumeData['resume_strategy'] = 'already_completed';
        } else {
            $resumeData['resume_strategy'] = 'manual_review_required';
        }
        
        Log::info("Resume analysis completed for sync {$syncHistoryId}", $resumeData);
        
        return $resumeData;
    }

    /**
     * Determine the appropriate resume point based on checkpoint data.
     */
    protected function determineResumePoint(array $checkpointData): array
    {
        $resumePoint = [
            'start_from' => 'beginning',
            'skip_project_setup' => false,
            'issues_processed' => 0,
            'last_issue_key' => null,
        ];
        
        // If project was stored successfully, we can skip project setup
        if (isset($checkpointData['project_stored']) && $checkpointData['project_stored']) {
            $resumePoint['skip_project_setup'] = true;
            $resumePoint['start_from'] = 'issues';
        }
        
        // If some issues were processed, note the progress
        if (isset($checkpointData['issues_processed'])) {
            $resumePoint['issues_processed'] = (int)$checkpointData['issues_processed'];
            $resumePoint['start_from'] = 'issues';
        }
        
        // If we have a specific issue to resume from
        if (isset($checkpointData['last_processed_issue'])) {
            $resumePoint['last_issue_key'] = $checkpointData['last_processed_issue'];
            $resumePoint['start_from'] = 'specific_issue';
        }
        
        return $resumePoint;
    }

    /**
     * Clean up old checkpoints to prevent database bloat.
     */
    public function cleanupOldCheckpoints(int $daysToKeep = 30): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        $deletedCount = JiraSyncCheckpoint::whereHas('syncHistory', function ($query) use ($cutoffDate) {
            $query->where('completed_at', '<', $cutoffDate)
                  ->whereIn('status', ['completed', 'failed']);
        })->delete();
        
        Log::info("Cleaned up {$deletedCount} old checkpoints older than {$daysToKeep} days");
        
        return $deletedCount;
    }

    /**
     * Create a recovery checkpoint for critical sync data.
     */
    public function createRecoveryCheckpoint(int $syncHistoryId, array $criticalData): JiraSyncCheckpoint
    {
        $checkpoint = JiraSyncCheckpoint::create([
            'jira_sync_history_id' => $syncHistoryId,
            'project_key' => 'RECOVERY',
            'checkpoint_type' => 'recovery',
            'checkpoint_data' => [
                'recovery_data' => $criticalData,
                'created_at' => now()->toISOString(),
            ],
            'status' => 'active',
        ]);

        Log::info("Recovery checkpoint created for sync {$syncHistoryId}", [
            'checkpoint_id' => $checkpoint->id,
            'data_keys' => array_keys($criticalData),
        ]);

        return $checkpoint;
    }

    /**
     * Validate checkpoint data integrity.
     */
    public function validateCheckpointIntegrity(int $checkpointId): array
    {
        $checkpoint = JiraSyncCheckpoint::findOrFail($checkpointId);
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ];
        
        // Check if checkpoint data is properly structured
        $data = $checkpoint->checkpoint_data ?? [];
        
        if (empty($data)) {
            $validation['warnings'][] = 'Checkpoint has no data';
        }
        
        // Check for required fields based on checkpoint type
        if ($checkpoint->checkpoint_type === 'project_sync') {
            if (!isset($data['project_stored'])) {
                $validation['warnings'][] = 'Missing project_stored status';
            }
            
            if (isset($data['issues_processed']) && isset($data['total_issues'])) {
                if ($data['issues_processed'] > $data['total_issues']) {
                    $validation['valid'] = false;
                    $validation['errors'][] = 'Issues processed exceeds total issues';
                }
            }
        }
        
        // Check if checkpoint timestamps are logical
        if ($checkpoint->completed_at && $checkpoint->completed_at < $checkpoint->created_at) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Completion time is before creation time';
        }
        
        Log::debug("Checkpoint {$checkpointId} validation completed", $validation);
        
        return $validation;
    }

    /**
     * Get checkpoint statistics for a sync operation.
     */
    public function getCheckpointStatistics(int $syncHistoryId): array
    {
        $checkpoints = $this->getCheckpointsForSync($syncHistoryId);
        
        $stats = [
            'total_checkpoints' => $checkpoints->count(),
            'completed_checkpoints' => $checkpoints->where('status', 'completed')->count(),
            'failed_checkpoints' => $checkpoints->where('status', 'failed')->count(),
            'active_checkpoints' => $checkpoints->where('status', 'active')->count(),
            'project_checkpoints' => $checkpoints->where('checkpoint_type', 'project_sync')->count(),
            'recovery_checkpoints' => $checkpoints->where('checkpoint_type', 'recovery')->count(),
            'first_checkpoint' => $checkpoints->min('created_at'),
            'last_checkpoint' => $checkpoints->max('created_at'),
        ];
        
        // Calculate success rate
        if ($stats['total_checkpoints'] > 0) {
            $stats['success_rate'] = round(
                ($stats['completed_checkpoints'] / $stats['total_checkpoints']) * 100, 
                2
            );
        } else {
            $stats['success_rate'] = 0;
        }
        
        return $stats;
    }

    /**
     * Batch create checkpoints for multiple projects.
     */
    public function batchCreateCheckpoints(int $syncHistoryId, array $projectKeys, array $baseData = []): Collection
    {
        $checkpoints = collect();
        
        DB::transaction(function () use ($syncHistoryId, $projectKeys, $baseData, &$checkpoints) {
            foreach ($projectKeys as $projectKey) {
                $checkpoints->push(
                    $this->createCheckpoint($syncHistoryId, $projectKey, $baseData)
                );
            }
        });
        
        Log::info("Batch created {$checkpoints->count()} checkpoints for sync {$syncHistoryId}");
        
        return $checkpoints;
    }
}