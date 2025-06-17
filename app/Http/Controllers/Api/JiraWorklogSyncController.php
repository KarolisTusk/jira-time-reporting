<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessJiraWorklogIncrementalSync;
use App\Models\JiraProject;
use App\Models\JiraSyncHistory;
use App\Models\JiraWorklogSyncStatus;
use App\Services\JiraWorklogIncrementalSyncService;
use App\Services\JiraWorklogSyncValidationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class JiraWorklogSyncController extends Controller
{
    protected JiraWorklogIncrementalSyncService $worklogSyncService;
    protected JiraWorklogSyncValidationService $validationService;

    public function __construct(
        JiraWorklogIncrementalSyncService $worklogSyncService,
        JiraWorklogSyncValidationService $validationService
    ) {
        $this->worklogSyncService = $worklogSyncService;
        $this->validationService = $validationService;
    }

    /**
     * Start an incremental worklog sync.
     */
    public function startWorklogSync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_keys' => 'required|array|min:1',
            'project_keys.*' => 'string|exists:jira_projects,project_key',
            'timeframe' => 'required|string|in:last24h,last7days,force_all',
            'sync_type' => 'sometimes|string|in:worklog_incremental',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $projectKeys = $request->input('project_keys');
            $timeframe = $request->input('timeframe');

            // Determine since date based on timeframe
            $sinceDate = null;
            switch ($timeframe) {
                case 'last24h':
                    $sinceDate = now()->subHours(24);
                    break;
                case 'last7days':
                    $sinceDate = now()->subDays(7);
                    break;
                case 'force_all':
                    $sinceDate = null; // Sync all worklogs
                    break;
            }

            // Create sync history record
            $syncHistory = JiraSyncHistory::create([
                'status' => 'pending',
                'sync_type' => 'worklog_incremental',
                'project_keys' => $projectKeys,
                'started_at' => now(),
                'triggered_by' => auth()->id(),
                'metadata' => [
                    'timeframe' => $timeframe,
                    'since_date' => $sinceDate?->toISOString(),
                    'triggered_by' => 'web_interface',
                    'user_id' => auth()->id(),
                ],
            ]);

            // Dispatch worklog sync job
            ProcessJiraWorklogIncrementalSync::dispatch([
                'project_keys' => $projectKeys,
                'since_date' => $sinceDate?->toISOString(),
                'manual' => true,
                'timeframe' => $timeframe,
            ], $syncHistory->id);

            // Auto-start queue worker for worklog sync if not running
            $this->ensureQueueWorkerRunning();

            Log::info('Worklog sync initiated via API', [
                'sync_history_id' => $syncHistory->id,
                'project_keys' => $projectKeys,
                'timeframe' => $timeframe,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Worklog sync started successfully',
                'sync_history_id' => $syncHistory->id,
                'estimated_duration' => $this->estimateSyncDuration($projectKeys, $timeframe),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to start worklog sync via API', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start worklog sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get worklog sync status for all projects.
     */
    public function getWorklogSyncStatus(): JsonResponse
    {
        try {
            $projectStatuses = JiraWorklogSyncStatus::orderBy('last_sync_at', 'desc')->get();

            $stats = [
                'lastSyncFormatted' => $this->getLastSyncFormatted(),
                'projectsSyncedToday' => $projectStatuses->where('last_sync_at', '>=', now()->startOfDay())->count(),
                'worklogsProcessedToday' => $projectStatuses
                    ->where('last_sync_at', '>=', now()->startOfDay())
                    ->sum('worklogs_processed'),
            ];

            $projectStatusMap = [];
            foreach ($projectStatuses as $status) {
                $projectStatusMap[$status->project_key] = $status->getTimeSinceLastSync();
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'project_statuses' => $projectStatusMap,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get worklog sync status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get worklog sync status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get worklog sync statistics.
     */
    public function getWorklogSyncStats(): JsonResponse
    {
        try {
            $allProjects = JiraProject::pluck('project_key')->toArray();
            $stats = $this->worklogSyncService->getWorklogSyncStats($allProjects);

            return response()->json([
                'success' => true,
                'stats' => [
                    'lastSyncFormatted' => $stats['last_sync_time'] ? 
                        Carbon::parse($stats['last_sync_time'])->diffForHumans() : 'Never',
                    'projectsSyncedToday' => $stats['recent_syncs'],
                    'worklogsProcessedToday' => $stats['total_worklogs_processed'],
                    'totalProjects' => $stats['total_projects'],
                    'projectsWithErrors' => $stats['projects_with_errors'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get worklog sync stats', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get worklog sync stats: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get worklog validation results summary.
     */
    public function getWorklogValidationResults(): JsonResponse
    {
        try {
            $allProjects = JiraProject::pluck('project_key')->toArray();
            $validationResults = [];

            foreach ($allProjects as $projectKey) {
                $syncStatus = JiraWorklogSyncStatus::where('project_key', $projectKey)->first();
                
                if ($syncStatus && $syncStatus->sync_metadata) {
                    $metadata = $syncStatus->sync_metadata;
                    
                    if (isset($metadata['validation'])) {
                        $validationResults[] = [
                            'project_key' => $projectKey,
                            'validation_passed' => $metadata['validation']['validation_passed'],
                            'sync_completeness_score' => $metadata['validation']['completeness_score'],
                            'discrepancy_percentage' => $metadata['validation']['discrepancy_percentage'],
                            'validation_errors' => [],
                            'validation_warnings' => [],
                        ];
                    }
                }
            }

            if (empty($validationResults)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No validation results available',
                    'validation_summary' => null,
                ]);
            }

            // Generate summary using validation service
            $summary = $this->validationService->generateValidationSummary($validationResults);
            $summary['timestamp'] = now()->toISOString();

            return response()->json([
                'success' => true,
                'validation_summary' => $summary,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get worklog validation results', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get worklog validation results: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync progress for a specific sync history.
     */
    public function getSyncProgress(int $syncHistoryId): JsonResponse
    {
        try {
            $syncHistory = JiraSyncHistory::find($syncHistoryId);

            if (!$syncHistory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sync history not found',
                ], 404);
            }

            // Extract progress information from metadata
            $metadata = $syncHistory->metadata ?? [];
            $worklogResults = $metadata['worklog_sync_results'] ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $syncHistory->id,
                    'status' => $syncHistory->status,
                    'start_time' => $syncHistory->started_at,
                    'end_time' => $syncHistory->completed_at,
                    'current_message' => $this->getCurrentProgressMessage($syncHistory),
                    'metadata' => $metadata,
                    'worklog_results' => $worklogResults,
                    'progress_percentage' => $this->calculateProgressPercentage($syncHistory),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get sync progress', [
                'sync_history_id' => $syncHistoryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get sync progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get formatted last sync time.
     */
    protected function getLastSyncFormatted(): string
    {
        $lastSync = JiraWorklogSyncStatus::orderBy('last_sync_at', 'desc')->first();
        
        if (!$lastSync || !$lastSync->last_sync_at) {
            return 'Never';
        }

        return $lastSync->last_sync_at->diffForHumans();
    }

    /**
     * Estimate sync duration based on projects and timeframe.
     */
    protected function estimateSyncDuration(array $projectKeys, string $timeframe): string
    {
        $baseTimePerProject = [
            'last24h' => 30,   // 30 seconds per project
            'last7days' => 120, // 2 minutes per project
            'force_all' => 300, // 5 minutes per project
        ];

        $estimatedSeconds = count($projectKeys) * ($baseTimePerProject[$timeframe] ?? 120);
        
        if ($estimatedSeconds < 60) {
            return "~{$estimatedSeconds} seconds";
        } elseif ($estimatedSeconds < 3600) {
            $minutes = round($estimatedSeconds / 60);
            return "~{$minutes} minutes";
        } else {
            $hours = round($estimatedSeconds / 3600, 1);
            return "~{$hours} hours";
        }
    }

    /**
     * Get current progress message from sync history.
     */
    protected function getCurrentProgressMessage(JiraSyncHistory $syncHistory): string
    {
        $metadata = $syncHistory->metadata ?? [];
        
        if (isset($metadata['current_message'])) {
            return $metadata['current_message'];
        }

        switch ($syncHistory->status) {
            case 'pending':
                return 'Worklog sync queued and waiting to start...';
            case 'in_progress':
                return 'Processing worklog sync...';
            case 'completed':
                return 'Worklog sync completed successfully';
            case 'completed_with_errors':
                return 'Worklog sync completed with some errors';
            case 'failed':
                return 'Worklog sync failed';
            default:
                return 'Status unknown';
        }
    }

    /**
     * Calculate progress percentage based on sync history.
     */
    protected function calculateProgressPercentage(JiraSyncHistory $syncHistory): int
    {
        switch ($syncHistory->status) {
            case 'pending':
                return 0;
            case 'in_progress':
                // Try to calculate based on projects processed
                $metadata = $syncHistory->metadata ?? [];
                $projectKeys = $syncHistory->project_keys ?? [];
                $results = $metadata['worklog_sync_results'] ?? [];
                $processedProjects = count($results['projects_processed'] ?? []);
                
                if (count($projectKeys) > 0) {
                    return min(90, round(($processedProjects / count($projectKeys)) * 90));
                }
                return 50; // Default middle progress
            case 'completed':
            case 'completed_with_errors':
                return 100;
            case 'failed':
                return 0;
            default:
                return 0;
        }
    }

    /**
     * Ensure a queue worker is running for the jira-worklog-sync queue.
     */
    protected function ensureQueueWorkerRunning(): void
    {
        try {
            // Check if a queue worker is already running for this queue
            $checkCommand = "ps aux | grep 'queue:work.*jira-worklog-sync' | grep -v grep | wc -l";
            $runningWorkers = (int) trim(shell_exec($checkCommand) ?: '0');
            
            if ($runningWorkers === 0) {
                Log::info('No queue worker found for jira-worklog-sync queue, starting one...');
                
                // Start the queue worker in the background using a more reliable method
                $artisanPath = base_path('artisan');
                $logPath = storage_path('logs/queue-worker.log');
                
                // Use nohup for better background process handling
                $startCommand = sprintf(
                    'nohup php %s queue:work --queue=jira-worklog-sync --tries=3 --timeout=1800 >> %s 2>&1 & echo $!',
                    escapeshellarg($artisanPath),
                    escapeshellarg($logPath)
                );
                
                $pid = trim(shell_exec($startCommand));
                
                // Verify the worker started by checking the PID
                if ($pid && is_numeric($pid)) {
                    Log::info('Queue worker started successfully for jira-worklog-sync queue', [
                        'pid' => $pid,
                        'command' => $startCommand,
                    ]);
                    
                    // Wait a moment and verify it's still running
                    sleep(1);
                    $verifyCommand = "ps -p {$pid} | grep -v PID | wc -l";
                    $isRunning = (int) trim(shell_exec($verifyCommand) ?: '0') > 0;
                    
                    if ($isRunning) {
                        Log::info('Queue worker verified running', ['pid' => $pid]);
                    } else {
                        Log::warning('Queue worker may have failed to start or stopped immediately', ['pid' => $pid]);
                    }
                } else {
                    Log::warning('Failed to get PID for started queue worker');
                }
            } else {
                Log::debug('Queue worker already running for jira-worklog-sync queue', [
                    'running_workers' => $runningWorkers,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to check/start queue worker', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}