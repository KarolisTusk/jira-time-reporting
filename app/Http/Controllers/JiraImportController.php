<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJiraSync;
use App\Models\JiraSyncHistory;
use App\Services\JiraImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia; // Or use standard Redirect for non-Inertia responses

class JiraImportController extends Controller
{
    protected JiraImportService $jiraImportService;

    public function __construct(JiraImportService $jiraImportService)
    {
        $this->jiraImportService = $jiraImportService;
    }

    /**
     * Triggers the JIRA data import process asynchronously.
     *
     * This method creates a sync history record and dispatches a job to perform
     * the actual sync work. It returns immediately with the sync history ID.
     */
    public function triggerImport(Request $request)
    {
        Log::info('JIRA import process triggered via controller.', [
            'user_id' => Auth::id(),
            'user_email' => Auth::user()?->email,
        ]);

        try {
            // Create sync history record
            $syncHistory = JiraSyncHistory::create([
                'started_at' => now(),
                'status' => 'pending',
                'triggered_by' => Auth::id(),
                'sync_type' => 'manual',
                'total_projects' => 0,
                'processed_projects' => 0,
                'total_issues' => 0,
                'processed_issues' => 0,
                'total_worklogs' => 0,
                'processed_worklogs' => 0,
                'total_users' => 0,
                'processed_users' => 0,
                'error_count' => 0,
            ]);

            Log::info('Created sync history record', [
                'sync_history_id' => $syncHistory->id,
                'user_id' => Auth::id(),
            ]);

            // Dispatch the sync job
            ProcessJiraSync::dispatch($syncHistory);

            Log::info('Dispatched JIRA sync job', [
                'sync_history_id' => $syncHistory->id,
                'job_class' => ProcessJiraSync::class,
            ]);

            return Redirect::route('settings.jira.show')
                ->with('status', 'info')
                ->with('message', 'JIRA sync has been started. You can monitor the progress in real-time or check the sync history.')
                ->with('sync_history_id', $syncHistory->id)
                ->with('sync_started', true);

        } catch (\Exception $e) {
            Log::critical('Critical error during JIRA import trigger: '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
            ]);

            return Redirect::route('settings.jira.show')
                ->with('status', 'error')
                ->with('message', 'A critical error occurred while starting the JIRA import: '.$e->getMessage());
        }
    }

    /**
     * Get the status of a specific sync.
     */
    public function getSyncStatus(Request $request, int $syncHistoryId)
    {
        try {
            $syncHistory = JiraSyncHistory::with('logs')
                ->where('id', $syncHistoryId)
                ->where('triggered_by', Auth::id()) // Ensure user can only see their own syncs
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'sync_history' => [
                    'id' => $syncHistory->id,
                    'status' => $syncHistory->status,
                    'progress_percentage' => $syncHistory->progress_percentage,
                    'project_progress_percentage' => $syncHistory->project_progress_percentage,
                    'issue_progress_percentage' => $syncHistory->issue_progress_percentage,
                    'worklog_progress_percentage' => $syncHistory->worklog_progress_percentage,
                    'user_progress_percentage' => $syncHistory->user_progress_percentage,
                    'totals' => [
                        'projects' => $syncHistory->total_projects,
                        'issues' => $syncHistory->total_issues,
                        'worklogs' => $syncHistory->total_worklogs,
                        'users' => $syncHistory->total_users,
                    ],
                    'processed' => [
                        'projects' => $syncHistory->processed_projects,
                        'issues' => $syncHistory->processed_issues,
                        'worklogs' => $syncHistory->processed_worklogs,
                        'users' => $syncHistory->processed_users,
                    ],
                    'error_count' => $syncHistory->error_count,
                    'has_errors' => $syncHistory->has_errors,
                    'is_running' => $syncHistory->is_running,
                    'started_at' => $syncHistory->started_at?->toIso8601String(),
                    'completed_at' => $syncHistory->completed_at?->toIso8601String(),
                    'formatted_duration' => $syncHistory->formatted_duration,
                    'sync_type' => $syncHistory->sync_type,
                ],
                'recent_logs' => $syncHistory->logs()
                    ->orderBy('timestamp', 'desc')
                    ->limit(10)
                    ->get()
                    ->map(function ($log) {
                        return [
                            'id' => $log->id,
                            'timestamp' => $log->timestamp->toIso8601String(),
                            'level' => $log->level,
                            'message' => $log->message,
                            'entity_type' => $log->entity_type,
                            'entity_id' => $log->entity_id,
                            'operation' => $log->operation,
                            'level_color' => $log->level_color,
                            'formatted_timestamp' => $log->formatted_timestamp,
                            'human_timestamp' => $log->human_timestamp,
                        ];
                    }),
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching sync status', [
                'sync_history_id' => $syncHistoryId,
                'user_id' => Auth::id(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sync status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a running sync.
     */
    public function cancelSync(Request $request, int $syncHistoryId)
    {
        try {
            $syncHistory = JiraSyncHistory::where('id', $syncHistoryId)
                ->where('triggered_by', Auth::id()) // Ensure user can only cancel their own syncs
                ->whereIn('status', ['pending', 'in_progress'])
                ->firstOrFail();

            // Mark as failed with cancellation reason
            $syncHistory->markAsFailed([
                'reason' => 'Cancelled by user',
                'cancelled_at' => now()->toIso8601String(),
                'cancelled_by' => Auth::id(),
            ]);

            // TODO: Implement actual job cancellation when Laravel supports it
            // For now, the job will complete but the status is marked as failed

            Log::info('Sync cancelled by user', [
                'sync_history_id' => $syncHistoryId,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync has been cancelled.',
            ]);

        } catch (\Exception $e) {
            Log::error('Error cancelling sync', [
                'sync_history_id' => $syncHistoryId,
                'user_id' => Auth::id(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel sync: '.$e->getMessage(),
            ], 500);
        }
    }
}
