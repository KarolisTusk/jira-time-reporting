<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJiraSync;
use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class JiraSyncHistoryController extends Controller
{
    /**
     * Display a paginated listing of sync history records.
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $request->validate([
            'status' => 'nullable|string|in:pending,in_progress,completed,failed',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|integer|exists:users,id',
            'sync_type' => 'nullable|string|in:manual,scheduled',
            'per_page' => 'nullable|integer|min:10|max:100',
            'sort_by' => 'nullable|string|in:started_at,completed_at,status,duration_seconds',
            'sort_direction' => 'nullable|string|in:asc,desc',
        ]);

        $query = JiraSyncHistory::with(['triggeredBy:id,name,email'])
            ->withCount(['logs as error_count' => function ($query) {
                $query->where('level', 'error');
            }]);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', $request->date_to.' 23:59:59');
        }

        if ($request->filled('user_id')) {
            $query->where('triggered_by', $request->user_id);
        }

        if ($request->filled('sync_type')) {
            $query->where('sync_type', $request->sync_type);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'started_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $perPage = $request->get('per_page', 15);
        $syncHistories = $query->paginate($perPage);

        // Add computed attributes for each record
        $syncHistories->getCollection()->transform(function ($syncHistory) {
            return $syncHistory->append([
                'progress_percentage',
                'formatted_duration',
                'has_errors',
                'is_running',
                'can_retry',
                'can_cancel',
            ]);
        });

        if ($request->expectsJson()) {
            return Response::json($syncHistories);
        }

        return Inertia::render('settings/JiraSyncHistory', [
            'syncHistories' => $syncHistories,
            'filters' => $request->only(['status', 'date_from', 'date_to', 'user_id', 'sync_type']),
            'sorting' => $request->only(['sort_by', 'sort_direction']),
        ]);
    }

    /**
     * Display detailed information about a specific sync history record.
     */
    public function show(Request $request, JiraSyncHistory $syncHistory): InertiaResponse|JsonResponse
    {
        // Load relationships and logs
        $syncHistory->load([
            'triggeredBy:id,name,email',
            'logs' => function ($query) use ($request) {
                // Apply log filters if provided
                if ($request->filled('log_level')) {
                    $query->where('level', $request->log_level);
                }
                if ($request->filled('entity_type')) {
                    $query->where('entity_type', $request->entity_type);
                }
                if ($request->filled('operation')) {
                    $query->where('operation', $request->operation);
                }

                $query->orderBy('timestamp', 'desc');
            },
        ]);

        // Paginate logs separately if requesting JSON
        if ($request->expectsJson() && $request->has('logs_page')) {
            $logsQuery = $syncHistory->logs();

            // Apply log filters
            if ($request->filled('log_level')) {
                $logsQuery->where('level', $request->log_level);
            }
            if ($request->filled('entity_type')) {
                $logsQuery->where('entity_type', $request->entity_type);
            }
            if ($request->filled('operation')) {
                $logsQuery->where('operation', $request->operation);
            }

            $logs = $logsQuery->orderBy('timestamp', 'desc')
                ->paginate($request->get('logs_per_page', 50), ['*'], 'logs_page');

            return Response::json([
                'sync_history' => $syncHistory->append([
                    'progress_percentage',
                    'formatted_duration',
                    'has_errors',
                    'is_running',
                    'can_retry',
                    'can_cancel',
                ]),
                'logs' => $logs,
            ]);
        }

        // Append computed attributes
        $syncHistory->append([
            'progress_percentage',
            'formatted_duration',
            'has_errors',
            'is_running',
            'can_retry',
            'can_cancel',
        ]);

        if ($request->expectsJson()) {
            return Response::json($syncHistory);
        }

        return Inertia::render('settings/JiraSyncHistoryDetail', [
            'syncHistory' => $syncHistory,
            'logs' => $syncHistory->logs->take(100), // Limit initial logs for performance
            'logFilters' => $request->only(['log_level', 'entity_type', 'operation']),
        ]);
    }

    /**
     * Delete a sync history record and its associated logs.
     */
    public function destroy(JiraSyncHistory $syncHistory): JsonResponse
    {
        // Only allow deletion of completed or failed syncs
        if ($syncHistory->status === 'in_progress' || $syncHistory->status === 'pending') {
            return Response::json([
                'error' => 'Cannot delete sync history for in-progress or pending syncs. Cancel the sync first.',
            ], 422);
        }

        // Delete all associated logs first
        $syncHistory->logs()->delete();

        // Delete the sync history record
        $syncHistory->delete();

        return Response::json([
            'message' => 'Sync history deleted successfully.',
        ]);
    }

    /**
     * Cancel an in-progress sync by terminating the job.
     */
    public function cancel(JiraSyncHistory $syncHistory): JsonResponse
    {
        if (! $syncHistory->can_cancel) {
            return Response::json([
                'error' => 'This sync cannot be cancelled.',
            ], 422);
        }

        try {
            // Update status to failed with cancellation message
            $syncHistory->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_details' => ['cancelled_by_user' => true, 'cancelled_at' => now()],
            ]);

            // Log the cancellation
            $syncHistory->logs()->create([
                'timestamp' => now(),
                'level' => 'info',
                'message' => 'Sync cancelled by user.',
                'context' => ['user_id' => Auth::id()],
                'operation' => 'cancel',
            ]);

            return Response::json([
                'message' => 'Sync cancelled successfully.',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'Failed to cancel sync: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry a failed sync by creating a new sync with the same parameters.
     */
    public function retry(JiraSyncHistory $syncHistory): JsonResponse
    {
        if (! $syncHistory->can_retry) {
            return Response::json([
                'error' => 'This sync cannot be retried.',
            ], 422);
        }

        try {
            // Create a new sync history record
            $newSyncHistory = JiraSyncHistory::create([
                'started_at' => now(),
                'status' => 'pending',
                'triggered_by' => Auth::id(),
                'sync_type' => 'manual', // Retries are always manual
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

            // Log the retry initiation
            $newSyncHistory->logs()->create([
                'timestamp' => now(),
                'level' => 'info',
                'message' => 'Sync retry initiated based on sync history ID: '.$syncHistory->id,
                'context' => [
                    'original_sync_id' => $syncHistory->id,
                    'user_id' => Auth::id(),
                ],
                'operation' => 'retry',
            ]);

            // Dispatch the new sync job
            ProcessJiraSync::dispatch($newSyncHistory);

            return Response::json([
                'message' => 'Sync retry initiated successfully.',
                'new_sync_id' => $newSyncHistory->id,
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'Failed to retry sync: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync logs with filtering and pagination.
     */
    public function logs(Request $request, JiraSyncHistory $syncHistory): JsonResponse
    {
        $request->validate([
            'level' => 'nullable|string|in:info,warning,error',
            'entity_type' => 'nullable|string|in:project,issue,worklog,user',
            'operation' => 'nullable|string|in:fetch,create,update,delete,retry,cancel',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = $syncHistory->logs();

        // Apply filters
        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->operation);
        }

        if ($request->filled('search')) {
            $query->where('message', 'like', '%'.$request->search.'%');
        }

        // Order by timestamp descending
        $query->orderBy('timestamp', 'desc');

        // Paginate results
        $perPage = $request->get('per_page', 50);
        $logs = $query->paginate($perPage);

        return Response::json($logs);
    }

    /**
     * Get sync statistics and summary data.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_syncs' => JiraSyncHistory::count(),
            'completed_syncs' => JiraSyncHistory::completed()->count(),
            'failed_syncs' => JiraSyncHistory::failed()->count(),
            'in_progress_syncs' => JiraSyncHistory::inProgress()->count(),
            'pending_syncs' => JiraSyncHistory::pending()->count(),
            'total_projects_synced' => JiraSyncHistory::sum('processed_projects'),
            'total_issues_synced' => JiraSyncHistory::sum('processed_issues'),
            'total_worklogs_synced' => JiraSyncHistory::sum('processed_worklogs'),
            'total_users_synced' => JiraSyncHistory::sum('processed_users'),
            'average_sync_duration' => JiraSyncHistory::whereNotNull('duration_seconds')
                ->avg('duration_seconds'),
            'last_successful_sync' => JiraSyncHistory::completed()
                ->latest('completed_at')
                ->first(['id', 'completed_at', 'duration_seconds']),
            'recent_errors' => JiraSyncLog::where('level', 'error')
                ->with(['syncHistory:id,started_at,status'])
                ->latest('timestamp')
                ->limit(5)
                ->get(['id', 'jira_sync_history_id', 'timestamp', 'message']),
        ];

        return Response::json($stats);
    }
}
