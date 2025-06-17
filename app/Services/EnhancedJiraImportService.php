<?php

namespace App\Services;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraProjectSyncStatus;
use App\Models\JiraSetting;
use App\Models\JiraSyncHistory;
use App\Models\JiraWorklog;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class EnhancedJiraImportService
{
    protected JiraApiService $jiraApiService;
    protected JiraSyncCheckpointService $checkpointService;
    protected JiraSyncProgressService $progressService;
    protected JiraSyncCacheService $cacheService;
    protected JiraSyncValidationService $validationService;

    private array $projectSpecificUserCache = [];
    private array $resourceTypeMapping = [
        'frontend' => [
            'keywords' => ['frontend', 'front-end', 'fe', 'ui', 'ux', 'react', 'vue', 'angular', 'javascript', 'css', 'html'],
            'priority' => 1,
        ],
        'backend' => [
            'keywords' => ['backend', 'back-end', 'be', 'api', 'server', 'php', 'laravel', 'database', 'mysql', 'postgresql'],
            'priority' => 1,
        ],
        'qa' => [
            'keywords' => ['qa', 'quality assurance', 'tester', 'testing', 'qe', 'automation', 'test', 'quality'],
            'priority' => 2,
        ],
        'devops' => [
            'keywords' => ['devops', 'dev ops', 'infrastructure', 'deployment', 'ci/cd', 'docker', 'kubernetes', 'aws', 'server admin'],
            'priority' => 2,
        ],
        'management' => [
            'keywords' => ['management', 'manager', 'pm', 'project manager', 'scrum master', 'lead', 'team lead', 'director'],
            'priority' => 3,
        ],
        'architect' => [
            'keywords' => ['architect', 'solution architect', 'technical architect', 'system architect', 'senior architect'],
            'priority' => 2,
        ],
        'content management' => [
            'keywords' => ['content', 'content manager', 'cms', 'content creation', 'copywriter', 'editor', 'writer'],
            'priority' => 4,
        ],
    ];

    public function __construct(
        JiraApiService $jiraApiService,
        JiraSyncCheckpointService $checkpointService,
        JiraSyncProgressService $progressService,
        JiraSyncCacheService $cacheService,
        JiraSyncValidationService $validationService
    ) {
        $this->jiraApiService = $jiraApiService;
        $this->checkpointService = $checkpointService;
        $this->progressService = $progressService;
        $this->cacheService = $cacheService;
        $this->validationService = $validationService;
    }

    /**
     * Enhanced JIRA data import with incremental sync support.
     */
    public function importDataWithOptions(array $options = []): array
    {
        Log::info('Enhanced JIRA data import process started.', $options);
        $startTime = microtime(true);
        
        $results = [
            'success' => true,
            'message' => '',
            'projects_processed' => 0,
            'issues_processed' => 0,
            'worklogs_imported' => 0,
            'users_processed' => 0,
            'total_hours_imported' => 0,
            'errors' => [],
            'sync_history_id' => null,
        ];

        // Get or create sync history record
        $syncHistory = $this->getOrCreateSyncHistory($options);
        $results['sync_history_id'] = $syncHistory->id;

        try {
            $syncHistory->markAsStarted();
            $this->progressService->broadcastProgress($syncHistory, 'Starting enhanced sync...');

            // Validate settings and options
            $settings = $this->validateSettings();
            $projectKeys = $this->getProjectKeysFromOptions($options, $settings);
            
            if (empty($projectKeys)) {
                throw new Exception('No valid project keys specified for sync.');
            }

            // Get date range for incremental sync
            $dateRange = $this->getDateRangeFromOptions($options);
            $onlyIssuesWithWorklogs = $options['only_issues_with_worklogs'] ?? false;

            $syncHistory->updateCurrentOperation('Fetching project information from JIRA...', 5);
            
            // Process each project
            foreach ($projectKeys as $index => $projectKey) {
                try {
                    $projectProgress = (($index + 1) / count($projectKeys)) * 90; // Reserve 90% for project processing
                    
                    $this->processProjectWithIncrementalSync(
                        $projectKey, 
                        $dateRange, 
                        $onlyIssuesWithWorklogs, 
                        $syncHistory, 
                        $results,
                        $projectProgress,
                        $options
                    );
                    
                    $results['projects_processed']++;
                    
                    // Memory management - force garbage collection after each project
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    
                    // Log memory usage for monitoring
                    $memoryUsage = memory_get_usage(true) / 1024 / 1024; // Convert to MB
                    Log::debug("Memory usage after project {$projectKey}: {$memoryUsage} MB");
                    
                } catch (Exception $e) {
                    $errorMessage = "Error processing project {$projectKey}: " . $e->getMessage();
                    Log::error($errorMessage);
                    $results['errors'][] = $errorMessage;
                    $syncHistory->addError($errorMessage, ['project_key' => $projectKey]);
                }
            }

            // Final validation and completion
            $this->validateSyncResults($results);
            
            // FIXED: Add comprehensive data validation
            if (config('jira.enable_validation', true)) {
                $validationResults = $this->validationService->validateSyncCompleteness($syncHistory);
                $syncHistory->update(['validation_results' => json_encode($validationResults)]);
                
                if ($validationResults['overall_status'] === 'invalid') {
                    Log::warning('Sync completed with data discrepancies', $validationResults);
                    $this->progressService->broadcastProgress($syncHistory, 'Sync completed with data validation warnings. Check logs for details.');
                } else {
                    $this->progressService->broadcastProgress($syncHistory, 'Sync completed successfully with data validation passed!');
                }
            }
            
            $syncHistory->markAsCompleted();
            
            // Invalidate cache for synced projects
            $this->invalidateCacheForSyncedProjects($projectKeys);
            
            $results['message'] = 'Enhanced JIRA data import completed successfully.';
            
        } catch (Exception $e) {
            $errorMessage = 'Enhanced JIRA sync failed: ' . $e->getMessage();
            Log::error($errorMessage, ['exception' => $e]);
            
            $results['success'] = false;
            $results['message'] = $errorMessage;
            $results['errors'][] = $errorMessage;
            
            $syncHistory->markAsFailed([$errorMessage]);
            $this->progressService->broadcastProgress($syncHistory, 'Sync failed: ' . $e->getMessage());
        }

        $duration = microtime(true) - $startTime;
        Log::info($results['message'] . " Duration: {$duration} seconds.", $results);

        return $results;
    }

    /**
     * Process a single project with incremental sync logic.
     */
    protected function processProjectWithIncrementalSync(
        string $projectKey, 
        ?array $dateRange, 
        bool $onlyIssuesWithWorklogs, 
        JiraSyncHistory $syncHistory, 
        array &$results,
        float $baseProgress,
        array $options = []
    ): void {
        Log::info("Processing project with incremental sync: {$projectKey}");
        
        // Update project sync status
        $projectSyncStatus = $this->getOrCreateProjectSyncStatus($projectKey);
        $lastSyncTime = $this->determineLastSyncTime($projectSyncStatus, $dateRange, $options);
        
        $syncHistory->updateCurrentOperation("Processing project: {$projectKey}...", $baseProgress);
        
        // Fetch and store project
        $projectData = $this->jiraApiService->getProjects([$projectKey]);
        if (empty($projectData)) {
            throw new Exception("Project {$projectKey} not found or not accessible.");
        }
        
        $localProject = $this->storeProject($projectData[0]);
        Log::info("Project {$projectKey} stored successfully.");
        
        // Create checkpoint for this project
        $checkpoint = $this->checkpointService->createCheckpoint($syncHistory->id, $projectKey, [
            'project_stored' => true,
            'last_sync_time' => $lastSyncTime?->toISOString(),
        ]);
        
        // Fetch issues with incremental logic using optimized API
        $syncHistory->updateCurrentOperation("Fetching issues for project: {$projectKey}...", $baseProgress + 5);
        
        // Use optimized v3 API for better performance
        $jiraV3Service = app(\App\Services\JiraApiServiceV3::class);
        $issuesData = $jiraV3Service->getIssuesIncremental(
            $projectKey, 
            $lastSyncTime, 
            $onlyIssuesWithWorklogs
        );
        
        Log::info("Fetched " . count($issuesData) . " issues for project {$projectKey}", [
            'project_key' => $projectKey,
            'issues_count' => count($issuesData),
            'incremental' => $lastSyncTime !== null,
            'since_date' => $lastSyncTime?->toISOString(),
        ]);
        
        // Process issues in batches
        $totalIssues = count($issuesData);
        $batchSize = config('jira.issue_batch_size', 25); // FIXED: Configurable batch size
        $processed = 0;
        
        foreach (array_chunk($issuesData, $batchSize) as $issueBatch) {
            foreach ($issueBatch as $issueData) {
                try {
                    $this->processIssueWithWorklogs(
                        $issueData, 
                        $localProject, 
                        $results, 
                        $lastSyncTime
                    );
                    
                    $processed++;
                    $issueProgress = $baseProgress + 10 + (($processed / $totalIssues) * 75);
                    $syncHistory->updateCurrentOperation(
                        "Processing issue {$processed}/{$totalIssues} for project: {$projectKey}", 
                        $issueProgress
                    );
                    
                } catch (Exception $e) {
                    $issueKey = $issueData['key'] ?? 'unknown';
                    $errorMessage = "Error processing issue {$issueKey}: " . $e->getMessage();
                    Log::error($errorMessage);
                    $results['errors'][] = $errorMessage;
                    $syncHistory->addError($errorMessage, ['issue_key' => $issueKey, 'project_key' => $projectKey]);
                }
            }
            
            // Update checkpoint after each batch
            $this->checkpointService->updateCheckpoint($checkpoint->id, [
                'issues_processed' => $processed,
                'total_issues' => $totalIssues,
            ]);
            
            // Small delay to respect rate limits
            usleep(100000); // 100ms delay between batches
        }
        
        // Update project sync status
        $projectSyncStatus->update([
            'last_sync_at' => now(),
            'last_sync_status' => 'completed',
            'issues_count' => $totalIssues,
            'last_error' => null,
        ]);
        
        Log::info("Completed processing project {$projectKey}", [
            'issues_processed' => $processed,
            'total_issues' => $totalIssues,
        ]);
    }

    /**
     * Process an individual issue and its worklogs.
     */
    protected function processIssueWithWorklogs(
        array $issueData, 
        JiraProject $localProject, 
        array &$results, 
        ?Carbon $lastSyncTime
    ): void {
        // Process assignee
        $assigneeData = Arr::get($issueData, 'fields.assignee');
        $localAssignee = $assigneeData ? $this->storeUser($assigneeData, $results) : null;
        
        // Store issue
        Log::debug("Storing issue for processing", [
            'issue_key' => $issueData['key'] ?? 'unknown',
            'issue_id' => $issueData['id'] ?? 'missing',
            'has_fields' => isset($issueData['fields']),
        ]);
        
        $localIssue = $this->storeIssue($issueData, $localProject->id, $localAssignee?->id);
        $results['issues_processed']++;
        
        // Fetch and process worklogs using optimized API
        $jiraV3Service = app(\App\Services\JiraApiServiceV3::class);
        $worklogsData = $jiraV3Service->getWorklogsForIssue($localIssue->issue_key);
        
        foreach ($worklogsData as $worklogData) {
            try {
                // Apply incremental filter to worklogs
                if ($lastSyncTime && $this->isWorklogOlderThanLastSync($worklogData, $lastSyncTime)) {
                    continue;
                }
                
                $authorData = Arr::get($worklogData, 'author');
                $localAuthor = $authorData ? $this->storeUser($authorData, $results) : null;
                
                if ($localAuthor) {
                    $worklog = $this->storeWorklogWithResourceType($worklogData, $localIssue->id, $localAuthor->id);
                    $results['worklogs_imported']++;
                    $results['total_hours_imported'] += ($worklog->time_spent_seconds / 3600);
                } else {
                    Log::warning("Skipping worklog for issue {$localIssue->issue_key} due to missing author data.");
                }
                
            } catch (Exception $e) {
                $wlId = $worklogData['id'] ?? 'unknown';
                $errorMessage = "Error processing worklog ID {$wlId} for issue {$localIssue->issue_key}: " . $e->getMessage();
                Log::error($errorMessage);
                $results['errors'][] = $errorMessage;
            }
        }
    }

    /**
     * Store a worklog with resource type classification.
     */
    protected function storeWorklogWithResourceType(array $worklogData, int $jiraIssueId, int $authorJiraAppUserId): JiraWorklog
    {
        $jiraId = $worklogData['id'] ?? null;
        
        if (empty($jiraId)) {
            throw new InvalidArgumentException('Worklog data from API is missing the required id field.');
        }
        if (empty($worklogData['timeSpentSeconds'])) {
            throw new InvalidArgumentException('Worklog data from API is missing the required timeSpentSeconds field.');
        }
        if (empty($worklogData['started'])) {
            throw new InvalidArgumentException('Worklog data from API is missing the required started field.');
        }
        
        // Determine resource type
        $resourceType = $this->determineResourceType($authorJiraAppUserId, $worklogData);
        
        return JiraWorklog::updateOrCreate(
            ['jira_id' => $worklogData['id']],
            [
                'jira_issue_id' => $jiraIssueId,
                'jira_app_user_id' => $authorJiraAppUserId,
                'time_spent_seconds' => $worklogData['timeSpentSeconds'],
                'started_at' => Carbon::parse($worklogData['started']),
                'resource_type' => $resourceType,
                'created_at' => isset($worklogData['created']) ? Carbon::parse($worklogData['created']) : now(),
                'updated_at' => isset($worklogData['updated']) ? Carbon::parse($worklogData['updated']) : now(),
            ]
        );
    }

    /**
     * Enhanced resource type determination with priority-based matching and caching.
     */
    protected function determineResourceType(int $authorId, array $worklogData): string
    {
        // Check cache first
        $cacheKey = "resource_type_{$authorId}";
        if (isset($this->projectSpecificUserCache[$cacheKey])) {
            return $this->projectSpecificUserCache[$cacheKey];
        }

        // Get user details
        $user = JiraAppUser::find($authorId);
        if (!$user) {
            return 'development'; // Default fallback
        }
        
        $detectedTypes = [];

        // 1. Check user display name and email (highest priority)
        $userText = strtolower($user->display_name . ' ' . ($user->email_address ?? ''));
        foreach ($this->resourceTypeMapping as $type => $config) {
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($userText, strtolower($keyword))) {
                    $detectedTypes[] = [
                        'type' => $type,
                        'priority' => $config['priority'],
                        'source' => 'user_profile',
                        'matched_keyword' => $keyword,
                    ];
                }
            }
        }

        // 2. Check worklog comment for contextual information (lower priority)
        $commentRaw = $worklogData['comment'] ?? '';
        $comment = '';
        
        // Handle comment field which can be string or array (rich text format)
        if (is_string($commentRaw)) {
            $comment = strtolower($commentRaw);
        } elseif (is_array($commentRaw)) {
            // Extract text from JIRA's rich text format
            $comment = strtolower($this->extractTextFromJiraComment($commentRaw));
        }
        
        if (!empty($comment)) {
            foreach ($this->resourceTypeMapping as $type => $config) {
                foreach ($config['keywords'] as $keyword) {
                    if (str_contains($comment, strtolower($keyword))) {
                        $detectedTypes[] = [
                            'type' => $type,
                            'priority' => $config['priority'] + 1, // Lower priority for comment-based detection
                            'source' => 'worklog_comment',
                            'matched_keyword' => $keyword,
                        ];
                    }
                }
            }
        }

        // 3. Determine best match based on priority and specificity
        if (!empty($detectedTypes)) {
            // Sort by priority (lower number = higher priority)
            usort($detectedTypes, function ($a, $b) {
                if ($a['priority'] === $b['priority']) {
                    // If same priority, prefer user profile over comment
                    return $a['source'] === 'user_profile' ? -1 : 1;
                }
                return $a['priority'] - $b['priority'];
            });

            $selectedType = $detectedTypes[0]['type'];

            // Log the detection for analysis
            Log::debug("Resource type detected for user {$user->display_name}", [
                'user_id' => $authorId,
                'detected_types' => $detectedTypes,
                'selected_type' => $selectedType,
                'selection_reason' => $detectedTypes[0],
            ]);

            // Cache the result for this user
            $this->projectSpecificUserCache[$cacheKey] = $selectedType;
            return $selectedType;
        }

        // 4. Apply heuristics for common patterns
        $heuristicType = $this->applyResourceTypeHeuristics($user, $worklogData);
        if ($heuristicType !== 'development') {
            $this->projectSpecificUserCache[$cacheKey] = $heuristicType;
            return $heuristicType;
        }

        // 5. Default fallback
        $this->projectSpecificUserCache[$cacheKey] = 'development';
        return 'development';
    }

    /**
     * Extract plain text from JIRA's rich text comment format.
     */
    protected function extractTextFromJiraComment($commentData): string
    {
        if (is_string($commentData)) {
            return $commentData;
        }
        
        if (!is_array($commentData)) {
            return '';
        }
        
        $text = '';
        
        // Handle JIRA's Document Format (rich text)
        if (isset($commentData['content']) && is_array($commentData['content'])) {
            foreach ($commentData['content'] as $content) {
                if (isset($content['content']) && is_array($content['content'])) {
                    foreach ($content['content'] as $textNode) {
                        if (isset($textNode['text']) && is_string($textNode['text'])) {
                            $text .= $textNode['text'] . ' ';
                        }
                    }
                } elseif (isset($content['text']) && is_string($content['text'])) {
                    $text .= $content['text'] . ' ';
                }
            }
        }
        
        // Fallback: try to extract any text values recursively
        if (empty($text)) {
            $text = $this->extractTextRecursively($commentData);
        }
        
        return trim($text);
    }
    
    /**
     * Recursively extract text from nested array structures.
     */
    protected function extractTextRecursively($data): string
    {
        $text = '';
        
        if (is_string($data)) {
            return $data . ' ';
        }
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($key === 'text' && is_string($value)) {
                    $text .= $value . ' ';
                } elseif (is_array($value)) {
                    $text .= $this->extractTextRecursively($value);
                }
            }
        }
        
        return $text;
    }

    /**
     * Apply heuristic rules for resource type detection.
     */
    protected function applyResourceTypeHeuristics(JiraAppUser $user, array $worklogData): string
    {
        $userName = strtolower($user->display_name);
        $userEmail = strtolower($user->email_address ?? '');

        // Check for common naming patterns
        if (str_contains($userName, 'senior') || str_contains($userName, 'lead')) {
            if (str_contains($userName, 'qa') || str_contains($userName, 'test')) {
                return 'qa';
            }
            if (str_contains($userName, 'dev') || str_contains($userName, 'engineer')) {
                return 'backend'; // Senior developers often work on backend
            }
        }

        // Check email domain patterns
        if (str_contains($userEmail, 'qa') || str_contains($userEmail, 'test')) {
            return 'qa';
        }

        // Check for time patterns (management typically logs during business hours)
        if (isset($worklogData['started'])) {
            $worklogTime = Carbon::parse($worklogData['started']);
            $hour = $worklogTime->hour;
            
            // If consistently logging during business hours and large time blocks, might be management
            $timeSpentHours = ($worklogData['timeSpentSeconds'] ?? 0) / 3600;
            if ($hour >= 9 && $hour <= 17 && $timeSpentHours >= 4) {
                // This is a weak signal, only use if no other indicators
                return 'management';
            }
        }

        return 'development'; // Default
    }

    /**
     * Get resource type statistics for a project.
     */
    public function getResourceTypeStatistics(string $projectKey): array
    {
        $project = JiraProject::where('project_key', $projectKey)->first();
        if (!$project) {
            return [];
        }

        $stats = JiraWorklog::select('resource_type')
            ->selectRaw('COUNT(*) as worklog_count')
            ->selectRaw('SUM(time_spent_seconds) as total_seconds')
            ->selectRaw('COUNT(DISTINCT jira_app_user_id) as unique_users')
            ->whereHas('issue', function ($query) use ($project) {
                $query->where('jira_project_id', $project->id);
            })
            ->groupBy('resource_type')
            ->get()
            ->map(function ($stat) {
                return [
                    'resource_type' => $stat->resource_type,
                    'worklog_count' => $stat->worklog_count,
                    'total_hours' => round($stat->total_seconds / 3600, 2),
                    'unique_users' => $stat->unique_users,
                ];
            });

        return $stats->toArray();
    }

    /**
     * Update resource types for existing worklogs (useful for re-classification).
     */
    public function reclassifyExistingWorklogs(string $projectKey = null): array
    {
        $query = JiraWorklog::with(['issue', 'user']);
        
        if ($projectKey) {
            $project = JiraProject::where('project_key', $projectKey)->first();
            if ($project) {
                $query->whereHas('issue', function ($q) use ($project) {
                    $q->where('jira_project_id', $project->id);
                });
            }
        }

        $worklogs = $query->get();
        $reclassified = 0;
        $errors = 0;

        foreach ($worklogs as $worklog) {
            try {
                $worklogData = [
                    'comment' => '', // We don't store comments, so empty
                    'started' => $worklog->started_at->toISOString(),
                    'timeSpentSeconds' => $worklog->time_spent_seconds,
                ];

                $newResourceType = $this->determineResourceType($worklog->jira_app_user_id, $worklogData);
                
                if ($newResourceType !== $worklog->resource_type) {
                    $worklog->update(['resource_type' => $newResourceType]);
                    $reclassified++;
                    
                    Log::debug("Reclassified worklog {$worklog->id}", [
                        'old_type' => $worklog->resource_type,
                        'new_type' => $newResourceType,
                        'user_id' => $worklog->jira_app_user_id,
                    ]);
                }
            } catch (Exception $e) {
                $errors++;
                Log::error("Error reclassifying worklog {$worklog->id}: " . $e->getMessage());
            }
        }

        Log::info("Worklog reclassification completed", [
            'total_processed' => $worklogs->count(),
            'reclassified' => $reclassified,
            'errors' => $errors,
            'project_key' => $projectKey,
        ]);

        return [
            'total_processed' => $worklogs->count(),
            'reclassified' => $reclassified,
            'errors' => $errors,
        ];
    }

    /**
     * Build JQL filter for incremental sync.
     */
    protected function buildIssuesFilter(string $projectKey, ?Carbon $lastSyncTime, bool $onlyIssuesWithWorklogs): string
    {
        $jql = "project = '{$projectKey}'";
        
        if ($lastSyncTime) {
            $jqlDate = $lastSyncTime->format('Y-m-d H:i');
            // FIXED: Include both created and updated items to prevent missing data
            $jql .= " AND (updated >= '{$jqlDate}' OR created >= '{$jqlDate}')";
        }
        
        if ($onlyIssuesWithWorklogs) {
            $jql .= " AND worklogDate is not EMPTY";
        }
        
        $jql .= " ORDER BY updated ASC";
        
        return $jql;
    }

    /**
     * Check if worklog is older than last sync time.
     */
    protected function isWorklogOlderThanLastSync(array $worklogData, Carbon $lastSyncTime): bool
    {
        $worklogUpdated = isset($worklogData['updated']) 
            ? Carbon::parse($worklogData['updated']) 
            : (isset($worklogData['created']) ? Carbon::parse($worklogData['created']) : null);
        
        return $worklogUpdated && $worklogUpdated->lt($lastSyncTime);
    }

    /**
     * Get worklogs with caching support.
     */
    protected function getWorklogsWithCaching(string $issueKey, string $projectKey): array
    {
        // Try to get cached worklogs first
        $cacheKey = "issue_worklogs:{$issueKey}";
        $cachedData = $this->cacheService->getCachedApiResponse('worklogs/' . $issueKey, []);
        
        if ($cachedData) {
            Log::debug("Using cached worklogs for issue {$issueKey}");
            return $cachedData['response'];
        }
        
        // Fetch from API if not cached
        $worklogsData = $this->jiraApiService->getWorklogsForIssue($issueKey);
        
        // Cache the response
        $this->cacheService->cacheApiResponse('worklogs/' . $issueKey, [], $worklogsData);
        
        Log::debug("Fetched and cached {count} worklogs for issue {issue}", [
            'count' => count($worklogsData),
            'issue' => $issueKey,
            'project' => $projectKey
        ]);
        
        return $worklogsData;
    }

    /**
     * Get issues with caching support.
     */
    protected function getIssuesWithCaching(string $projectKey, array $params): array
    {
        // For incremental syncs (with lastSyncTime), don't use cache as data changes frequently
        $isIncrementalSync = str_contains($params['jql'] ?? '', 'updated >=');
        
        if ($isIncrementalSync) {
            Log::debug("Skipping cache for incremental sync of project {$projectKey}");
            return $this->jiraApiService->getIssuesForProject($projectKey, $params);
        }
        
        // Try to get cached issues for full syncs
        $cachedData = $this->cacheService->getCachedApiResponse('issues/' . $projectKey, $params);
        
        if ($cachedData) {
            Log::debug("Using cached issues for project {$projectKey}");
            return $cachedData['response'];
        }
        
        // Fetch from API if not cached
        $issuesData = $this->jiraApiService->getIssuesForProject($projectKey, $params);
        
        // Cache the response for non-incremental syncs
        $this->cacheService->cacheApiResponse('issues/' . $projectKey, $params, $issuesData);
        
        Log::debug("Fetched and cached {count} issues for project {project}", [
            'count' => count($issuesData),
            'project' => $projectKey
        ]);
        
        return $issuesData;
    }

    /**
     * Invalidate cache for synced projects.
     */
    protected function invalidateCacheForSyncedProjects(array $projectKeys): void
    {
        foreach ($projectKeys as $projectKey) {
            $this->cacheService->invalidateProjectCache($projectKey);
        }
        
        Log::info("Invalidated cache for {count} projects", [
            'count' => count($projectKeys),
            'projects' => $projectKeys
        ]);
    }

    /**
     * Determine the appropriate last sync time.
     */
    protected function determineLastSyncTime(?JiraProjectSyncStatus $projectSyncStatus, ?array $dateRange, array $options = []): ?Carbon
    {
        // Force full sync - ignore all previous sync history
        if (isset($options['force_full_sync']) && $options['force_full_sync']) {
            Log::info("Force full sync requested - ignoring previous sync history");
            return null; // This will sync all data from beginning
        }
        
        // If date range is specified, use start date
        if ($dateRange && isset($dateRange['start'])) {
            return Carbon::parse($dateRange['start']);
        }
        
        // If project sync status exists and was successful, use last sync time
        if ($projectSyncStatus && $projectSyncStatus->last_sync_at && $projectSyncStatus->last_sync_status === 'completed') {
            return $projectSyncStatus->last_sync_at;
        }
        
        // For initial sync, return null (sync all data)
        return null;
    }

    /**
     * Get or create project sync status.
     */
    protected function getOrCreateProjectSyncStatus(string $projectKey): JiraProjectSyncStatus
    {
        return JiraProjectSyncStatus::firstOrCreate(
            ['project_key' => $projectKey],
            [
                'last_sync_at' => null,
                'last_sync_status' => 'pending',
                'issues_count' => 0,
                'last_error' => null,
            ]
        );
    }

    /**
     * Get existing sync history or create a new one if not provided.
     */
    protected function getOrCreateSyncHistory(array $options): JiraSyncHistory
    {
        // If sync_history_id is provided, use existing record
        if (isset($options['sync_history_id']) && $options['sync_history_id']) {
            $syncHistory = JiraSyncHistory::find($options['sync_history_id']);
            if ($syncHistory) {
                Log::info("Using existing sync history record", ['sync_history_id' => $syncHistory->id]);
                return $syncHistory;
            }
        }
        
        // Create new sync history record
        Log::info("Creating new sync history record");
        return JiraSyncHistory::create([
            'started_at' => now(),
            'status' => 'pending',
            'sync_type' => $options['sync_type'] ?? 'manual',
            'triggered_by' => $options['triggered_by'] ?? auth()->id(),
            'total_projects' => 0,
            'processed_projects' => 0,
            'total_issues' => 0,
            'processed_issues' => 0,
            'total_worklogs' => 0,
            'processed_worklogs' => 0,
            'total_users' => 0,
            'processed_users' => 0,
            'error_count' => 0,
            'progress_percentage' => 0,
            'current_operation' => 'Initializing...',
        ]);
    }

    /**
     * Validate JIRA settings.
     */
    protected function validateSettings(): JiraSetting
    {
        $settings = JiraSetting::first();
        if (!$settings || empty($settings->project_keys)) {
            throw new Exception('JIRA settings not configured or no project keys specified.');
        }
        return $settings;
    }

    /**
     * Get project keys from options or settings.
     */
    protected function getProjectKeysFromOptions(array $options, JiraSetting $settings): array
    {
        if (isset($options['project_keys']) && is_array($options['project_keys'])) {
            return array_filter($options['project_keys']);
        }
        
        return $settings->project_keys ?? [];
    }

    /**
     * Get date range from options.
     */
    protected function getDateRangeFromOptions(array $options): ?array
    {
        if (isset($options['date_range']) && is_array($options['date_range'])) {
            return $options['date_range'];
        }
        
        return null;
    }

    /**
     * Validate sync results for data integrity.
     */
    protected function validateSyncResults(array $results): void
    {
        // Check if total hours imported seems reasonable
        if ($results['total_hours_imported'] < 0) {
            throw new Exception('Invalid total hours imported: negative value detected.');
        }
        
        // Log important metrics for validation
        Log::info('Sync validation completed', [
            'total_hours_imported' => $results['total_hours_imported'],
            'worklogs_imported' => $results['worklogs_imported'],
            'issues_processed' => $results['issues_processed'],
            'projects_processed' => $results['projects_processed'],
        ]);
    }

    // Enhanced methods with conflict resolution (JIRA as source of truth)
    public function storeProject(array $projectData): JiraProject
    {
        return $this->storeProjectWithConflictResolution($projectData);
    }

    public function storeUser(array $userData, array &$results): ?JiraAppUser
    {
        return $this->storeUserWithConflictResolution($userData, $results);
    }

    public function storeIssue(array $issueData, int $jiraProjectId, ?int $assigneeJiraAppUserId): JiraIssue
    {
        return $this->storeIssueWithConflictResolution($issueData, $jiraProjectId, $assigneeJiraAppUserId);
    }

    /**
     * Store project with conflict resolution (JIRA as source of truth).
     */
    protected function storeProjectWithConflictResolution(array $projectData): JiraProject
    {
        if (empty($projectData['id'])) {
            throw new InvalidArgumentException('Project data from API is missing the required id field.');
        }
        if (empty($projectData['key'])) {
            throw new InvalidArgumentException('Project data from API is missing the required key field.');
        }
        if (empty($projectData['name'])) {
            throw new InvalidArgumentException('Project data from API is missing the required name field.');
        }

        $jiraId = $projectData['id'];
        $existingProject = JiraProject::where('jira_id', $jiraId)->first();

        if ($existingProject) {
            // Check for conflicts and resolve in favor of JIRA
            $conflicts = $this->detectProjectConflicts($existingProject, $projectData);
            
            if (!empty($conflicts)) {
                Log::info("Resolving project conflicts for {$projectData['key']}", [
                    'conflicts' => $conflicts,
                    'jira_data_wins' => 'JIRA is source of truth',
                ]);
            }

            // Update with JIRA data (source of truth)
            $existingProject->update([
                'project_key' => $projectData['key'],
                'name' => $projectData['name'],
                'updated_at' => now(),
            ]);

            return $existingProject;
        }

        // Create new project
        return JiraProject::create([
            'jira_id' => $projectData['id'],
            'project_key' => $projectData['key'],
            'name' => $projectData['name'],
        ]);
    }

    /**
     * Store user with conflict resolution (JIRA as source of truth).
     */
    protected function storeUserWithConflictResolution(array $userData, array &$results): ?JiraAppUser
    {
        $accountId = $userData['accountId'] ?? null;

        if (empty($accountId)) {
            Log::warning('Skipping user creation/update due to missing accountId', ['userData' => $userData]);
            return null;
        }

        if (empty($userData['displayName'])) {
            throw new InvalidArgumentException('User data from API is missing the required displayName field for accountId: ' . $accountId);
        }

        $existingUser = JiraAppUser::where('jira_account_id', $accountId)->first();

        if ($existingUser) {
            // Check for conflicts and resolve in favor of JIRA
            $conflicts = $this->detectUserConflicts($existingUser, $userData);
            
            if (!empty($conflicts)) {
                Log::info("Resolving user conflicts for {$accountId}", [
                    'conflicts' => $conflicts,
                    'jira_data_wins' => 'JIRA is source of truth',
                ]);
            }

            // Update with JIRA data (source of truth)
            $existingUser->update([
                'display_name' => $userData['displayName'],
                'email_address' => $userData['emailAddress'] ?? null,
                'updated_at' => now(),
            ]);

            $results['users_processed']++;
            return $existingUser;
        }

        // Create new user
        $user = JiraAppUser::create([
            'jira_account_id' => $accountId,
            'display_name' => $userData['displayName'],
            'email_address' => $userData['emailAddress'] ?? null,
        ]);

        $results['users_processed']++;
        return $user;
    }

    /**
     * Store issue with conflict resolution (JIRA as source of truth).
     */
    protected function storeIssueWithConflictResolution(array $issueData, int $jiraProjectId, ?int $assigneeJiraAppUserId): JiraIssue
    {
        $jiraId = $issueData['id'] ?? null;

        if (empty($jiraId)) {
            throw new InvalidArgumentException('Issue data from API is missing the required id field.');
        }

        $fields = $issueData['fields'] ?? null;
        if (!$fields || !isset($fields['summary'], $fields['status']['name'])) {
            $errorMessage = 'Missing required issue fields (summary or status.name) from API for issue key: ' . $issueData['key'];
            Log::error($errorMessage, ['issueData' => $issueData]);
            throw new InvalidArgumentException($errorMessage);
        }

        $existingIssue = JiraIssue::where('jira_id', $jiraId)->first();
        $originalEstimateSeconds = Arr::get($fields, 'timetracking.originalEstimateSeconds');

        // Extract labels and epic information
        $labels = $fields['labels'] ?? [];
        $epicKey = null;
        
        // Extract epic key from parent field (for epic link)
        if (isset($fields['parent']['key'])) {
            $epicKey = $fields['parent']['key'];
        }

        $issueAttributes = [
            'jira_id' => $jiraId,  // ✅ CRITICAL FIX: Add missing jira_id field
            'jira_project_id' => $jiraProjectId,
            'issue_key' => $issueData['key'],
            'summary' => $fields['summary'],
            'status' => $fields['status']['name'],
            'labels' => $labels,
            'epic_key' => $epicKey,
            'assignee_jira_app_user_id' => $assigneeJiraAppUserId,
            'original_estimate_seconds' => $originalEstimateSeconds,
            'updated_at' => now(),
        ];

        if ($existingIssue) {
            // Check for conflicts and resolve in favor of JIRA
            $conflicts = $this->detectIssueConflicts($existingIssue, $issueAttributes);
            
            if (!empty($conflicts)) {
                Log::info("Resolving issue conflicts for {$issueData['key']}", [
                    'conflicts' => $conflicts,
                    'jira_data_wins' => 'JIRA is source of truth',
                ]);
            }

            // Update with JIRA data (source of truth)
            $existingIssue->update($issueAttributes);
            return $existingIssue;
        }

        // Create new issue
        return JiraIssue::create($issueAttributes);
    }

    /**
     * Detect conflicts between existing project and JIRA data.
     */
    protected function detectProjectConflicts(JiraProject $existingProject, array $jiraData): array
    {
        $conflicts = [];

        if ($existingProject->project_key !== $jiraData['key']) {
            $conflicts['project_key'] = [
                'local' => $existingProject->project_key,
                'jira' => $jiraData['key'],
            ];
        }

        if ($existingProject->name !== $jiraData['name']) {
            $conflicts['name'] = [
                'local' => $existingProject->name,
                'jira' => $jiraData['name'],
            ];
        }

        return $conflicts;
    }

    /**
     * Detect conflicts between existing user and JIRA data.
     */
    protected function detectUserConflicts(JiraAppUser $existingUser, array $jiraData): array
    {
        $conflicts = [];

        if ($existingUser->display_name !== $jiraData['displayName']) {
            $conflicts['display_name'] = [
                'local' => $existingUser->display_name,
                'jira' => $jiraData['displayName'],
            ];
        }

        $jiraEmail = $jiraData['emailAddress'] ?? null;
        if ($existingUser->email_address !== $jiraEmail) {
            $conflicts['email_address'] = [
                'local' => $existingUser->email_address,
                'jira' => $jiraEmail,
            ];
        }

        return $conflicts;
    }

    /**
     * Detect conflicts between existing issue and JIRA data.
     */
    protected function detectIssueConflicts(JiraIssue $existingIssue, array $jiraAttributes): array
    {
        $conflicts = [];

        if ($existingIssue->issue_key !== $jiraAttributes['issue_key']) {
            $conflicts['issue_key'] = [
                'local' => $existingIssue->issue_key,
                'jira' => $jiraAttributes['issue_key'],
            ];
        }

        if ($existingIssue->summary !== $jiraAttributes['summary']) {
            $conflicts['summary'] = [
                'local' => $existingIssue->summary,
                'jira' => $jiraAttributes['summary'],
            ];
        }

        if ($existingIssue->status !== $jiraAttributes['status']) {
            $conflicts['status'] = [
                'local' => $existingIssue->status,
                'jira' => $jiraAttributes['status'],
            ];
        }

        if ($existingIssue->assignee_jira_app_user_id !== $jiraAttributes['assignee_jira_app_user_id']) {
            $conflicts['assignee'] = [
                'local' => $existingIssue->assignee_jira_app_user_id,
                'jira' => $jiraAttributes['assignee_jira_app_user_id'],
            ];
        }

        if ($existingIssue->original_estimate_seconds !== $jiraAttributes['original_estimate_seconds']) {
            $conflicts['original_estimate'] = [
                'local' => $existingIssue->original_estimate_seconds,
                'jira' => $jiraAttributes['original_estimate_seconds'],
            ];
        }

        return $conflicts;
    }

    /**
     * Handle deleted JIRA entities (cleanup orphaned local data).
     */
    public function cleanupDeletedJiraData(array $syncedJiraIds, string $entityType): array
    {
        $cleanupResults = [
            'deleted_count' => 0,
            'deleted_entities' => [],
        ];

        switch ($entityType) {
            case 'projects':
                $orphanedProjects = JiraProject::whereNotIn('jira_id', $syncedJiraIds)->get();
                foreach ($orphanedProjects as $project) {
                    Log::info("Deleting orphaned project (deleted in JIRA): {$project->project_key}");
                    $cleanupResults['deleted_entities'][] = [
                        'type' => 'project',
                        'key' => $project->project_key,
                        'jira_id' => $project->jira_id,
                    ];
                    $project->delete();
                    $cleanupResults['deleted_count']++;
                }
                break;

            case 'issues':
                $orphanedIssues = JiraIssue::whereNotIn('jira_id', $syncedJiraIds)->get();
                foreach ($orphanedIssues as $issue) {
                    Log::info("Deleting orphaned issue (deleted in JIRA): {$issue->issue_key}");
                    $cleanupResults['deleted_entities'][] = [
                        'type' => 'issue',
                        'key' => $issue->issue_key,
                        'jira_id' => $issue->jira_id,
                    ];
                    $issue->delete();
                    $cleanupResults['deleted_count']++;
                }
                break;

            case 'worklogs':
                $orphanedWorklogs = JiraWorklog::whereNotIn('jira_id', $syncedJiraIds)->get();
                foreach ($orphanedWorklogs as $worklog) {
                    Log::info("Deleting orphaned worklog (deleted in JIRA): {$worklog->jira_id}");
                    $cleanupResults['deleted_entities'][] = [
                        'type' => 'worklog',
                        'jira_id' => $worklog->jira_id,
                        'issue_id' => $worklog->jira_issue_id,
                    ];
                    $worklog->delete();
                    $cleanupResults['deleted_count']++;
                }
                break;

            case 'users':
                $orphanedUsers = JiraAppUser::whereNotIn('jira_account_id', $syncedJiraIds)->get();
                foreach ($orphanedUsers as $user) {
                    Log::info("Deleting orphaned user (deleted in JIRA): {$user->display_name}");
                    $cleanupResults['deleted_entities'][] = [
                        'type' => 'user',
                        'display_name' => $user->display_name,
                        'jira_account_id' => $user->jira_account_id,
                    ];
                    $user->delete();
                    $cleanupResults['deleted_count']++;
                }
                break;
        }

        if ($cleanupResults['deleted_count'] > 0) {
            Log::info("Cleanup completed for {$entityType}", $cleanupResults);
        }

        return $cleanupResults;
    }

    /**
     * Validate data integrity between local and JIRA data.
     */
    public function validateDataIntegrity(string $projectKey): array
    {
        $validation = [
            'consistent' => true,
            'issues_found' => [],
            'summary' => [],
        ];

        // Check for orphaned data
        $localProject = JiraProject::where('project_key', $projectKey)->first();
        if (!$localProject) {
            $validation['consistent'] = false;
            $validation['issues_found'][] = "Project {$projectKey} exists in JIRA but not locally";
            return $validation;
        }

        // Check issues consistency
        $localIssues = JiraIssue::where('jira_project_id', $localProject->id)->count();
        $validation['summary']['local_issues_count'] = $localIssues;

        // Check worklogs consistency
        $localWorklogs = JiraWorklog::whereHas('issue', function ($query) use ($localProject) {
            $query->where('jira_project_id', $localProject->id);
        })->count();
        $validation['summary']['local_worklogs_count'] = $localWorklogs;

        // Check for data quality issues
        $issuesWithoutKeys = JiraIssue::where('jira_project_id', $localProject->id)
            ->whereNull('issue_key')
            ->count();
        
        if ($issuesWithoutKeys > 0) {
            $validation['consistent'] = false;
            $validation['issues_found'][] = "{$issuesWithoutKeys} issues without keys";
        }

        $worklogsWithoutTime = JiraWorklog::whereHas('issue', function ($query) use ($localProject) {
            $query->where('jira_project_id', $localProject->id);
        })->where('time_spent_seconds', '<=', 0)->count();

        if ($worklogsWithoutTime > 0) {
            $validation['consistent'] = false;
            $validation['issues_found'][] = "{$worklogsWithoutTime} worklogs with invalid time";
        }

        return $validation;
    }
}