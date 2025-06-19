<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEnhancedJiraSync;
use App\Models\JiraProject;
use App\Models\JiraProjectSyncStatus;
use App\Models\JiraSetting;
use App\Models\JiraSyncHistory;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogSyncStatus;
use App\Services\EnhancedJiraImportService;
use App\Services\JiraApiService;
use App\Services\JiraSyncCheckpointService;
use App\Services\JiraWorklogIncrementalSyncService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EnhancedJiraSyncController extends Controller
{
    protected EnhancedJiraImportService $enhancedImportService;
    protected JiraApiService $jiraApiService;
    protected JiraSyncCheckpointService $checkpointService;
    protected JiraWorklogIncrementalSyncService $worklogSyncService;

    public function __construct(
        EnhancedJiraImportService $enhancedImportService,
        JiraApiService $jiraApiService,
        JiraSyncCheckpointService $checkpointService,
        JiraWorklogIncrementalSyncService $worklogSyncService
    ) {
        $this->enhancedImportService = $enhancedImportService;
        $this->jiraApiService = $jiraApiService;
        $this->checkpointService = $checkpointService;
        $this->worklogSyncService = $worklogSyncService;
    }

    /**
     * Display the enhanced JIRA sync admin page.
     */
    public function index(): Response
    {
        // Get available projects from JIRA settings
        $jiraSettings = JiraSetting::first();
        $availableProjects = collect();
        
        if ($jiraSettings && !empty($jiraSettings->project_keys)) {
            // Get projects from the configured project keys in settings
            $configuredKeys = $jiraSettings->project_keys;
            
            // First, try to get projects from the jira_projects table if they exist
            $existingProjects = JiraProject::whereIn('project_key', $configuredKeys)
                ->select('id', 'project_key', 'name')
                ->get()
                ->keyBy('project_key');
            
            // Create a collection with all configured projects, using stored info where available
            $availableProjects = collect($configuredKeys)->map(function ($projectKey) use ($existingProjects) {
                if ($existingProjects->has($projectKey)) {
                    $project = $existingProjects->get($projectKey);
                    return [
                        'id' => $project->id,
                        'project_key' => $project->project_key,
                        'name' => $project->name,
                    ];
                } else {
                    // For projects not yet synced, create basic info
                    return [
                        'id' => null,
                        'project_key' => $projectKey,
                        'name' => $projectKey, // Use key as name until first sync
                    ];
                }
            })->sortBy('project_key')->values();
        }

        // Get connection status
        $connectionStatus = $this->getConnectionStatus();

        // Get dashboard stats
        $stats = $this->getDashboardStats();

        // Get project sync statuses for configured projects only
        $configuredKeys = $jiraSettings ? $jiraSettings->project_keys : [];
        $projectStatuses = JiraProjectSyncStatus::whereIn('project_key', $configuredKeys)
            ->orderBy('project_key')
            ->get();

        // Get recent sync history
        $recentSyncs = JiraSyncHistory::with('triggeredBy')
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get();

        // Get worklog sync statistics
        $worklogStats = $this->getWorklogSyncStats($configuredKeys);

        return Inertia::render('admin/EnhancedJiraSync', [
            'availableProjects' => $availableProjects,
            'connectionStatus' => $connectionStatus,
            'stats' => $stats,
            'projectStatuses' => $projectStatuses,
            'recentSyncs' => $recentSyncs,
            'worklogStats' => $worklogStats,
        ]);
    }

    /**
     * Start a manual enhanced JIRA sync.
     */
    public function startSync(Request $request): JsonResponse
    {
        try {
            // Get configured project keys from JIRA settings
            $jiraSettings = JiraSetting::first();
            $configuredProjectKeys = $jiraSettings ? $jiraSettings->project_keys : [];
            
            $validator = Validator::make($request->all(), [
                'project_keys' => 'required|array|min:1',
                'project_keys.*' => [
                    'string',
                    function ($attribute, $value, $fail) use ($configuredProjectKeys) {
                        if (!in_array($value, $configuredProjectKeys)) {
                            $fail("Project key {$value} is not configured in JIRA settings.");
                        }
                    }
                ],
                'sync_type' => ['required', Rule::in(['incremental', 'last7days', 'last30days', 'custom', 'force_full'])],
                'date_range' => 'nullable|array',
                'date_range.start' => 'nullable|date',
                'date_range.end' => 'nullable|date|after_or_equal:date_range.start',
                'only_issues_with_worklogs' => 'boolean',
                'reclassify_resources' => 'boolean',
                'validate_data' => 'boolean',
                'cleanup_orphaned' => 'boolean',
                'batch_config' => 'array',
                'batch_config.issue_batch_size' => 'integer|min:10|max:200',
                'batch_config.rate_limit' => 'integer|min:100|max:1000',
                'batch_config.max_retry_attempts' => 'integer|min:1|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Check if any projects are configured
            if (empty($configuredProjectKeys)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No projects are configured. Please configure projects in JIRA Settings first.',
                ], 400);
            }

            // Use database transaction with row-level locking to prevent race conditions
            return DB::transaction(function () use ($request, $configuredProjectKeys) {
                // Check for active syncs with row-level locking (PostgreSQL compatible)
                $activeSyncExists = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
                    ->lockForUpdate() // Prevents concurrent access
                    ->exists();
                    
                if ($activeSyncExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Another sync operation is already in progress. Please wait for it to complete.',
                        'code' => 'SYNC_IN_PROGRESS'
                    ], 409);
                }

                // Perform pre-flight validation
                $preflightResult = $this->performPreflightValidation($request->all());
                if (!$preflightResult['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pre-flight validation failed',
                        'errors' => $preflightResult['errors'],
                    ], 400);
                }

                // Create sync history record atomically
                $syncHistory = JiraSyncHistory::create([
                    'started_at' => now(),
                    'status' => 'pending',
                    'sync_type' => 'manual',
                    'triggered_by' => auth()->id(),
                    'total_projects' => count($request->input('project_keys')),
                    'processed_projects' => 0,
                    'total_issues' => 0,
                    'processed_issues' => 0,
                    'total_worklogs' => 0,
                    'processed_worklogs' => 0,
                    'total_users' => 0,
                    'processed_users' => 0,
                    'error_count' => 0,
                    'progress_percentage' => 0,
                    'current_operation' => 'Initializing enhanced sync...',
                ]);

                // Prepare sync options including sync history ID
                $syncOptions = [
                    'project_keys' => $request->input('project_keys'),
                    'sync_type' => 'manual',
                    'triggered_by' => auth()->id(),
                    'sync_history_id' => $syncHistory->id,
                    'date_range' => $request->input('date_range'),
                    'only_issues_with_worklogs' => $request->boolean('only_issues_with_worklogs'),
                    'reclassify_resources' => $request->boolean('reclassify_resources'),
                    'validate_data' => $request->boolean('validate_data'),
                    'cleanup_orphaned' => $request->boolean('cleanup_orphaned'),
                    'batch_config' => $request->input('batch_config', []),
                    'force_full_sync' => $request->boolean('force_full_sync'),
                    'user_sync_type' => $request->input('sync_type'), // Store the user's chosen sync type
                ];

                try {
                    // Dispatch the enhanced sync job with unique job id to prevent duplicates
                    ProcessEnhancedJiraSync::dispatch($syncOptions)
                        ->onQueue('jira-sync') // Use dedicated queue
                        ->afterCommit() // Only dispatch after DB transaction commits
                        ->delay(now()->addSeconds(2)); // Small delay to ensure UI updates

                    Log::info('Enhanced JIRA sync initiated by user', [
                        'user_id' => auth()->id(),
                        'project_keys' => $syncOptions['project_keys'],
                        'sync_type' => $syncOptions['sync_type'],
                        'sync_history_id' => $syncHistory->id,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Enhanced JIRA sync started successfully',
                        'data' => [
                            'sync_history_id' => $syncHistory->id,
                            'estimated_projects' => count($syncOptions['project_keys']),
                            'sync_type' => $request->input('sync_type'),
                            'status' => 'pending',
                        ],
                    ]);
                    
                } catch (Exception $jobException) {
                    // If job dispatch fails, mark sync as failed
                    $syncHistory->update([
                        'status' => 'failed',
                        'current_operation' => 'Failed to dispatch sync job: ' . $jobException->getMessage(),
                        'completed_at' => now(),
                    ]);
                    
                    throw $jobException;
                }
            });

        } catch (Exception $e) {
            Log::error('Failed to start enhanced JIRA sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start sync: ' . $e->getMessage(),
                'code' => 'SYNC_START_FAILED'
            ], 500);
        }
    }

    /**
     * Cancel an active sync operation.
     */
    public function cancelSync(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sync_id' => 'nullable|integer|exists:jira_sync_histories,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $syncId = $request->input('sync_id');
            
            if ($syncId) {
                // Cancel specific sync
                $syncHistory = JiraSyncHistory::find($syncId);
                if (!$syncHistory || !$syncHistory->can_cancel) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sync cannot be cancelled or does not exist',
                    ], 400);
                }
            } else {
                // Cancel any active sync
                $syncHistory = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])
                    ->orderBy('started_at', 'desc')
                    ->first();
                    
                if (!$syncHistory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active sync found to cancel',
                    ], 404);
                }
            }

            // Mark as failed with cancellation message (cancelled status not allowed by DB constraint)
            $syncHistory->update([
                'status' => 'failed',
                'current_operation' => 'Cancelled by user',
                'completed_at' => now(),
            ]);

            Log::info('Enhanced JIRA sync cancelled by user', [
                'sync_id' => $syncHistory->id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync cancellation requested successfully',
                'data' => ['sync_id' => $syncHistory->id],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cancel enhanced JIRA sync', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync progress for active operations.
     */
    public function getSyncProgress(Request $request): JsonResponse
    {
        try {
            $syncId = $request->query('sync_id');
            
            $query = JiraSyncHistory::query();
            
            if ($syncId) {
                $query->where('id', $syncId);
            } else {
                $query->whereIn('status', ['pending', 'in_progress'])
                    ->orderBy('started_at', 'desc');
            }
            
            $syncs = $query->with(['checkpoints' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])->get();

            return response()->json([
                'success' => true,
                'data' => $syncs->map(function ($sync) {
                    $stats = $sync->getCurrentStats();
                    $recentIssues = $sync->getRecentlySyncedIssues(8);
                    
                    return [
                        'sync_history_id' => $sync->id,
                        'status' => $sync->status,
                        'progress_percentage' => $sync->progress_percentage,
                        'project_progress_percentage' => $sync->project_progress_percentage,
                        'issue_progress_percentage' => $sync->issue_progress_percentage,
                        'worklog_progress_percentage' => $sync->worklog_progress_percentage,
                        'user_progress_percentage' => $sync->user_progress_percentage,
                        'totals' => [
                            'projects' => $sync->total_projects,
                            'issues' => $sync->total_issues,
                            'worklogs' => $sync->total_worklogs,
                            'users' => $sync->total_users,
                        ],
                        'processed' => [
                            'projects' => $sync->processed_projects,
                            'issues' => $sync->processed_issues,
                            'worklogs' => $sync->processed_worklogs,
                            'users' => $sync->processed_users,
                        ],
                        'error_count' => $sync->error_count,
                        'has_errors' => $sync->has_errors,
                        'is_running' => $sync->is_running,
                        'started_at' => $sync->started_at?->toIso8601String(),
                        'completed_at' => $sync->completed_at?->toIso8601String(),
                        'formatted_duration' => $sync->formatted_duration,
                        'progress_data' => [
                            'current_operation' => $sync->current_operation,
                            'estimated_completion' => $sync->estimated_completion?->toIso8601String(),
                        ],
                        'checkpoints' => $sync->checkpoints->take(5),
                        'is_stale' => $sync->is_stale,
                        // Enhanced feedback features
                        'detailed_stats' => $stats,
                        'recent_issues' => $recentIssues,
                        'estimated_completion_human' => $stats['estimated_completion'],
                        'duration_human' => $stats['duration'],
                    ];
                }),
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get sync progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test JIRA connection using optimized v3 API.
     */
    public function testConnection(): JsonResponse
    {
        try {
            // Use optimized v3 API service for connection testing
            $jiraV3Service = app(\App\Services\JiraApiServiceV3::class);
            $result = $jiraV3Service->testConnection();
            
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['data'],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry a failed project sync.
     */
    public function retryProject(Request $request, string $projectKey): JsonResponse
    {
        try {
            // Validate project exists
            $project = JiraProject::where('project_key', $projectKey)->first();
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                ], 404);
            }

            // Check if there's an active sync
            $activeSyncs = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])->count();
            if ($activeSyncs > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot retry while another sync is in progress',
                ], 409);
            }

            // Start retry for single project
            $syncOptions = [
                'project_keys' => [$projectKey],
                'sync_type' => 'manual_retry',
                'triggered_by' => auth()->id(),
                'only_issues_with_worklogs' => false,
                'validate_data' => true,
            ];

            ProcessEnhancedJiraSync::dispatch($syncOptions)
                ->onQueue('jira-sync');

            Log::info('Project retry initiated', [
                'project_key' => $projectKey,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Retry initiated for project {$projectKey}",
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry project: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync metrics and statistics.
     */
    public function getMetrics(Request $request): JsonResponse
    {
        try {
            $projectKey = $request->query('project_key');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            // Resource type breakdown
            $resourceStats = JiraWorklog::getResourceTypeStats(
                $projectKey ? JiraProject::where('project_key', $projectKey)->first()?->id : null,
                $dateFrom,
                $dateTo
            );

            // Project statistics
            $projectStats = JiraProject::with(['syncStatus'])->get()->map(function ($project) {
                return [
                    'project_key' => $project->project_key,
                    'name' => $project->name,
                    'last_sync_at' => $project->syncStatus?->last_sync_at,
                    'last_sync_status' => $project->syncStatus?->last_sync_status ?? 'never_synced',
                    'issues_count' => $project->syncStatus?->issues_count ?? 0,
                ];
            });

            // Recent sync performance
            $recentSyncs = JiraSyncHistory::where('status', 'completed')
                ->where('started_at', '>=', now()->subDays(30))
                ->orderBy('started_at', 'desc')
                ->limit(20)
                ->get(['id', 'started_at', 'completed_at', 'duration_seconds', 'total_projects', 'processed_worklogs']);

            return response()->json([
                'success' => true,
                'data' => [
                    'resource_breakdown' => $resourceStats,
                    'project_stats' => $projectStats,
                    'recent_syncs' => $recentSyncs,
                    'performance_metrics' => [
                        'avg_sync_duration' => $recentSyncs->avg('duration_seconds'),
                        'total_syncs_30_days' => $recentSyncs->count(),
                        'avg_worklogs_per_sync' => $recentSyncs->avg('processed_worklogs'),
                    ],
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get metrics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Perform data validation.
     */
    public function validateData(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_keys' => 'nullable|array',
                'project_keys.*' => 'string|exists:jira_projects,project_key',
                'validation_types' => 'required|array',
                'validation_types.*' => Rule::in(['integrity', 'duplicates', 'orphaned', 'hours_baseline']),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $projectKeys = $request->input('project_keys', []);
            $validationTypes = $request->input('validation_types');
            $results = [];

            foreach ($validationTypes as $type) {
                switch ($type) {
                    case 'integrity':
                        $results['integrity'] = $this->validateDataIntegrity($projectKeys);
                        break;
                    case 'duplicates':
                        $results['duplicates'] = $this->findDuplicateData($projectKeys);
                        break;
                    case 'orphaned':
                        $results['orphaned'] = $this->findOrphanedData($projectKeys);
                        break;
                    case 'hours_baseline':
                        $results['hours_baseline'] = $this->validateHoursBaseline();
                        break;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data validation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reclassify resource types for existing worklogs.
     */
    public function reclassifyResources(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_keys' => 'nullable|array',
                'project_keys.*' => 'string|exists:jira_projects,project_key',
                'force_reclassify' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $projectKeys = $request->input('project_keys');
            $results = [];

            if (empty($projectKeys)) {
                // Reclassify all projects
                $results = $this->enhancedImportService->reclassifyExistingWorklogs();
            } else {
                // Reclassify specific projects
                foreach ($projectKeys as $projectKey) {
                    $results[$projectKey] = $this->enhancedImportService->reclassifyExistingWorklogs($projectKey);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Resource reclassification completed',
                'data' => $results,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resource reclassification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get connection status.
     */
    protected function getConnectionStatus(): array
    {
        try {
            $settings = JiraSetting::first();
            if (!$settings) {
                return ['connected' => false, 'lastChecked' => null];
            }

            // Simple check - if we have settings, assume connected
            // In production, you might want to cache this and refresh periodically
            return [
                'connected' => !empty($settings->jira_host) && !empty($settings->api_token),
                'lastChecked' => now()->toISOString(),
            ];
        } catch (Exception $e) {
            return ['connected' => false, 'lastChecked' => null];
        }
    }

    /**
     * Get dashboard statistics.
     */
    protected function getDashboardStats(): array
    {
        try {
            $totalProjects = JiraProject::count();
            
            $lastSync = JiraSyncHistory::where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->first();
            
            $totalHours = JiraWorklog::sum('time_spent_seconds') / 3600;
            
            $activeSyncs = JiraSyncHistory::whereIn('status', ['pending', 'in_progress'])->count();

            return [
                'totalProjects' => $totalProjects,
                'lastSyncFormatted' => $lastSync ? $lastSync->completed_at->diffForHumans() : 'Never',
                'totalHours' => number_format($totalHours, 2) . 'h',
                'activeSyncs' => $activeSyncs,
            ];
        } catch (Exception $e) {
            return [
                'totalProjects' => 0,
                'lastSyncFormatted' => 'Error',
                'totalHours' => '0h',
                'activeSyncs' => 0,
            ];
        }
    }

    /**
     * Perform pre-flight validation before starting sync.
     */
    protected function performPreflightValidation(array $config): array
    {
        $errors = [];

        try {
            // Test JIRA connection
            $connectionResult = $this->jiraApiService->testConnection();
            if (!$connectionResult['success']) {
                $errors[] = 'JIRA connection test failed: ' . $connectionResult['message'];
            }

            // Validate project keys exist in JIRA
            foreach ($config['project_keys'] as $projectKey) {
                $projects = $this->jiraApiService->getProjects([$projectKey]);
                if (empty($projects)) {
                    $errors[] = "Project {$projectKey} not found or not accessible in JIRA";
                }
            }

            // Validate date range
            if ($config['sync_type'] === 'custom' && isset($config['date_range'])) {
                $startDate = Carbon::parse($config['date_range']['start']);
                $endDate = Carbon::parse($config['date_range']['end']);
                
                if ($startDate->gt($endDate)) {
                    $errors[] = 'Start date must be before end date';
                }
                
                if ($startDate->gt(now())) {
                    $errors[] = 'Start date cannot be in the future';
                }
            }

        } catch (Exception $e) {
            $errors[] = 'Pre-flight validation error: ' . $e->getMessage();
        }

        return [
            'success' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate data integrity.
     */
    protected function validateDataIntegrity(array $projectKeys): array
    {
        $results = [];
        
        foreach ($projectKeys as $projectKey) {
            $results[$projectKey] = $this->enhancedImportService->validateDataIntegrity($projectKey);
        }
        
        return $results;
    }

    /**
     * Find duplicate data.
     */
    protected function findDuplicateData(array $projectKeys): array
    {
        $duplicates = [
            'worklogs' => [],
            'issues' => [],
        ];

        // Find duplicate worklogs by JIRA ID
        $duplicateWorklogs = JiraWorklog::select('jira_id', DB::raw('COUNT(*) as count'))
            ->whereHas('issue.project', function ($query) use ($projectKeys) {
                if (!empty($projectKeys)) {
                    $query->whereIn('project_key', $projectKeys);
                }
            })
            ->groupBy('jira_id')
            ->having('count', '>', 1)
            ->get();

        $duplicates['worklogs'] = $duplicateWorklogs;

        return $duplicates;
    }

    /**
     * Find orphaned data.
     */
    protected function findOrphanedData(array $projectKeys): array
    {
        // This is a simplified version - in reality you'd check against JIRA API
        return [
            'orphaned_issues' => 0,
            'orphaned_worklogs' => 0,
            'orphaned_users' => 0,
        ];
    }

    /**
     * Validate hours baseline (119,033.02 hours requirement).
     */
    protected function validateHoursBaseline(): array
    {
        $totalHours = JiraWorklog::sum('time_spent_seconds') / 3600;
        $expectedBaseline = 119033.02;
        $variance = abs($totalHours - $expectedBaseline);
        $variancePercentage = ($variance / $expectedBaseline) * 100;

        return [
            'current_total_hours' => round($totalHours, 2),
            'expected_baseline' => $expectedBaseline,
            'variance' => round($variance, 2),
            'variance_percentage' => round($variancePercentage, 2),
            'within_tolerance' => $variancePercentage <= 1.0, // 1% tolerance
        ];
    }

    /**
     * Download detailed error logs for a specific sync operation.
     */
    public function downloadErrorLog(JiraSyncHistory $syncHistory)
    {
        try {
            // Check if sync exists and has errors
            if (!$syncHistory) {
                return response()->json(['error' => 'Sync history not found'], 404);
            }

            // Prepare detailed error information
            $errorContent = $this->generateErrorReport($syncHistory);
            
            // Generate filename with timestamp
            $filename = "jira-sync-{$syncHistory->id}-errors-" . now()->format('Y-m-d_H-i-s') . '.txt';
            
            return response()->streamDownload(function () use ($errorContent) {
                echo $errorContent;
            }, $filename, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);

        } catch (Exception $e) {
            Log::error('Failed to download error log', [
                'sync_id' => $syncHistory->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to generate error log: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed error information for a sync operation (JSON API).
     */
    public function getErrorDetails(JiraSyncHistory $syncHistory)
    {
        try {
            // Get detailed error information
            $errorDetails = $this->parseErrorDetails($syncHistory);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'sync_id' => $syncHistory->id,
                    'error_count' => $syncHistory->error_count,
                    'status' => $syncHistory->status,
                    'started_at' => $syncHistory->started_at,
                    'duration' => $syncHistory->duration_seconds,
                    'errors' => $errorDetails,
                    'summary' => $this->generateErrorSummary($errorDetails),
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to get error details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a comprehensive error report for download.
     */
    private function generateErrorReport(JiraSyncHistory $syncHistory): string
    {
        $report = [];
        $report[] = "=".str_repeat("=", 80)."=";
        $report[] = " JIRA SYNC ERROR REPORT - SYNC #{$syncHistory->id}";
        $report[] = "=".str_repeat("=", 80)."=";
        $report[] = "";
        $report[] = "Generated: " . now()->format('Y-m-d H:i:s T');
        $report[] = "Sync Started: " . ($syncHistory->started_at ? $syncHistory->started_at->format('Y-m-d H:i:s T') : 'N/A');
        $report[] = "Sync Status: " . strtoupper($syncHistory->status);
        $report[] = "Duration: " . ($syncHistory->duration_seconds ? $syncHistory->duration_seconds . ' seconds' : 'N/A');
        $report[] = "Total Errors: " . ($syncHistory->error_count ?? 0);
        $report[] = "";
        
        // Sync Summary
        $report[] = "SYNC SUMMARY:";
        $report[] = str_repeat("-", 40);
        $report[] = "Projects: {$syncHistory->processed_projects}/{$syncHistory->total_projects}";
        $report[] = "Issues: {$syncHistory->processed_issues}/{$syncHistory->total_issues}";
        $report[] = "Worklogs: {$syncHistory->processed_worklogs}/{$syncHistory->total_worklogs}";
        $report[] = "Users: {$syncHistory->processed_users}/{$syncHistory->total_users}";
        $report[] = "Progress: {$syncHistory->progress_percentage}%";
        $report[] = "Current Operation: " . ($syncHistory->current_operation ?? 'N/A');
        $report[] = "";

        // Error Details
        if ($syncHistory->error_details) {
            $report[] = "DETAILED ERROR INFORMATION:";
            $report[] = str_repeat("-", 40);
            
            $errorDetails = $this->parseErrorDetails($syncHistory);
            
            if (!empty($errorDetails)) {
                $errorSummary = $this->generateErrorSummary($errorDetails);
                
                $report[] = "ERROR SUMMARY:";
                foreach ($errorSummary as $type => $count) {
                    $report[] = "  - {$type}: {$count} errors";
                }
                $report[] = "";
                
                $report[] = "INDIVIDUAL ERRORS:";
                $report[] = str_repeat("-", 40);
                
                foreach ($errorDetails as $index => $error) {
                    $report[] = "Error #" . ($index + 1) . ":";
                    $report[] = "  Type: " . ($error['type'] ?? 'Unknown');
                    $report[] = "  Message: " . ($error['message'] ?? 'No message');
                    $report[] = "  Context: " . ($error['context'] ?? 'No context');
                    $report[] = "  Timestamp: " . ($error['timestamp'] ?? 'Unknown');
                    if (isset($error['stack_trace'])) {
                        $report[] = "  Stack Trace: " . substr($error['stack_trace'], 0, 200) . "...";
                    }
                    $report[] = "";
                }
            } else {
                $report[] = "Raw error data:";
                $report[] = $syncHistory->error_details;
                $report[] = "";
            }
        } else {
            $report[] = "No detailed error information available.";
            $report[] = "";
        }

        // System Information
        $report[] = "SYSTEM INFORMATION:";
        $report[] = str_repeat("-", 40);
        $report[] = "Laravel Version: " . app()->version();
        $report[] = "PHP Version: " . PHP_VERSION;
        $report[] = "Memory Usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB";
        $report[] = "Peak Memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB";
        $report[] = "";
        
        $report[] = "=".str_repeat("=", 80)."=";
        $report[] = " END OF REPORT";
        $report[] = "=".str_repeat("=", 80)."=";

        return implode("\n", $report);
    }

    /**
     * Parse error details from the stored JSON or text.
     */
    private function parseErrorDetails(JiraSyncHistory $syncHistory): array
    {
        if (!$syncHistory->error_details) {
            return [];
        }

        // error_details is already cast as array in the model, so check if it's already an array
        if (is_array($syncHistory->error_details)) {
            return $syncHistory->error_details;
        }

        // If it's a string, try to decode as JSON
        if (is_string($syncHistory->error_details)) {
            $decoded = json_decode($syncHistory->error_details, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // If not JSON, treat as plain text and create a structured error
            return [
                [
                    'type' => 'General Error',
                    'message' => $syncHistory->error_details,
                    'context' => 'Raw error data',
                    'timestamp' => $syncHistory->updated_at ? $syncHistory->updated_at->toISOString() : null,
                ]
            ];
        }

        // Fallback for any other type
        return [
            [
                'type' => 'Unknown Error',
                'message' => 'Error details in unexpected format',
                'context' => 'Type: ' . gettype($syncHistory->error_details),
                'timestamp' => $syncHistory->updated_at ? $syncHistory->updated_at->toISOString() : null,
            ]
        ];
    }

    /**
     * Get worklog sync statistics.
     */
    private function getWorklogSyncStats(array $configuredKeys): array
    {
        try {
            $stats = $this->worklogSyncService->getWorklogSyncStats($configuredKeys);
            
            // Get today's sync data
            $todaysSyncs = JiraWorklogSyncStatus::whereIn('project_key', $configuredKeys)
                ->where('last_sync_at', '>=', now()->startOfDay())
                ->get();

            return [
                'lastSyncFormatted' => $stats['last_sync_time'] ? 
                    Carbon::parse($stats['last_sync_time'])->diffForHumans() : 'Never',
                'projectsSyncedToday' => $todaysSyncs->count(),
                'worklogsProcessedToday' => $todaysSyncs->sum('worklogs_processed'),
                'totalProjects' => $stats['total_projects'],
                'projectsWithErrors' => $stats['projects_with_errors'],
                'totalWorklogsProcessed' => $stats['total_worklogs_processed'],
                'totalWorklogsAdded' => $stats['total_worklogs_added'],
                'totalWorklogsUpdated' => $stats['total_worklogs_updated'],
            ];
        } catch (Exception $e) {
            Log::error('Failed to get worklog sync stats', [
                'error' => $e->getMessage(),
                'configured_keys' => $configuredKeys,
            ]);

            return [
                'lastSyncFormatted' => 'Error',
                'projectsSyncedToday' => 0,
                'worklogsProcessedToday' => 0,
                'totalProjects' => 0,
                'projectsWithErrors' => 0,
                'totalWorklogsProcessed' => 0,
                'totalWorklogsAdded' => 0,
                'totalWorklogsUpdated' => 0,
            ];
        }
    }

    /**
     * Generate error summary by type.
     */
    private function generateErrorSummary(array $errorDetails): array
    {
        $summary = [];
        
        foreach ($errorDetails as $error) {
            $type = $error['type'] ?? 'Unknown';
            $summary[$type] = ($summary[$type] ?? 0) + 1;
        }
        
        // Sort by count descending
        arsort($summary);
        
        return $summary;
    }
}
