<?php

namespace App\Services;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraWorklog;
use App\Models\JiraSyncHistory;
use App\Models\JiraWorklogSyncStatus;
use App\Services\JiraWorklogSyncValidationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JiraWorklogIncrementalSyncService
{
    protected JiraApiServiceV3 $jiraApiService;
    protected JiraSyncProgressService $progressService;
    protected JiraWorklogSyncValidationService $validationService;

    // Resource type mapping for worklog classification
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
            'keywords' => ['qa', 'test', 'testing', 'quality', 'assurance', 'bug', 'defect', 'automation'],
            'priority' => 2,
        ],
        'devops' => [
            'keywords' => ['devops', 'deployment', 'infrastructure', 'ci/cd', 'docker', 'kubernetes', 'aws', 'server'],
            'priority' => 2,
        ],
        'management' => [
            'keywords' => ['management', 'planning', 'meeting', 'review', 'standup', 'retrospective', 'admin'],
            'priority' => 3,
        ],
        'documentation' => [
            'keywords' => ['documentation', 'docs', 'readme', 'wiki', 'guide', 'manual', 'spec'],
            'priority' => 4,
        ],
    ];

    public function __construct(
        JiraApiServiceV3 $jiraApiService,
        JiraSyncProgressService $progressService,
        JiraWorklogSyncValidationService $validationService
    ) {
        $this->jiraApiService = $jiraApiService;
        $this->progressService = $progressService;
        $this->validationService = $validationService;
    }

    /**
     * Perform incremental worklog sync for specified projects.
     */
    public function syncWorklogsIncremental(
        array $projectKeys,
        ?Carbon $sinceDate = null,
        ?JiraSyncHistory $syncHistory = null
    ): array {
        $results = [
            'worklogs_processed' => 0,
            'worklogs_added' => 0,
            'worklogs_updated' => 0,
            'worklogs_skipped' => 0,
            'errors' => [],
            'projects_processed' => [],
            'validation_results' => [],
            'overall_validation_summary' => null,
        ];

        Log::info('Starting incremental worklog sync', [
            'projects' => $projectKeys,
            'since_date' => $sinceDate?->toISOString(),
        ]);

        try {
            $totalProjects = count($projectKeys);
            $completedProjects = 0;

            foreach ($projectKeys as $projectKey) {
                // Update progress - starting project
                if ($syncHistory) {
                    $this->progressService->broadcastProgress(
                        $syncHistory,
                        "Syncing worklogs for project {$projectKey}...",
                        false,
                        [
                            'current_project' => $projectKey,
                            'projects_completed' => $completedProjects,
                            'total_projects' => $totalProjects,
                            'progress_percentage' => round(($completedProjects / $totalProjects) * 90), // Reserve 10% for validation
                        ]
                    );
                }

                $projectResults = $this->syncProjectWorklogsIncremental(
                    $projectKey,
                    $sinceDate,
                    $syncHistory
                );

                $results['worklogs_processed'] += $projectResults['worklogs_processed'];
                $results['worklogs_added'] += $projectResults['worklogs_added'];
                $results['worklogs_updated'] += $projectResults['worklogs_updated'];
                $results['worklogs_skipped'] += $projectResults['worklogs_skipped'];
                $results['errors'] = array_merge($results['errors'], $projectResults['errors']);
                $results['projects_processed'][] = $projectKey;

                $completedProjects++;

                // Update progress - completed project
                if ($syncHistory) {
                    $this->progressService->broadcastProgress(
                        $syncHistory,
                        "Completed worklog sync for project {$projectKey}",
                        false,
                        [
                            'current_project' => $projectKey,
                            'projects_completed' => $completedProjects,
                            'total_projects' => $totalProjects,
                            'progress_percentage' => round(($completedProjects / $totalProjects) * 90),
                            'project_results' => $projectResults,
                        ]
                    );
                }

                // Run validation for this project if enabled
                if (config('jira.enable_validation', true)) {
                    try {
                        if ($syncHistory) {
                            $this->progressService->broadcastProgress(
                                $syncHistory,
                                "Validating worklog sync for project {$projectKey}...",
                                false,
                                ['validation_in_progress' => true]
                            );
                        }

                        $validationResults = $this->validationService->validateWorklogSyncResults(
                            $projectKey,
                            $projectResults,
                            $sinceDate
                        );

                        $results['validation_results'][$projectKey] = $validationResults;

                        // Store validation results
                        $this->validationService->storeValidationResults($projectKey, $validationResults);

                        // Update worklog sync status with validation results
                        $this->updateWorklogSyncStatus($projectKey, $projectResults, $validationResults);

                        // Add validation warnings/errors to main results
                        if (!$validationResults['validation_passed']) {
                            $results['errors'] = array_merge(
                                $results['errors'],
                                $validationResults['validation_errors']
                            );
                        }

                    } catch (Exception $e) {
                        $validationError = "Validation failed for {$projectKey}: " . $e->getMessage();
                        Log::warning($validationError);
                        $results['errors'][] = $validationError;
                        
                        // Still update sync status without validation results
                        $this->updateWorklogSyncStatus($projectKey, $projectResults);
                    }
                } else {
                    // Validation disabled - update sync status without validation results
                    $this->updateWorklogSyncStatus($projectKey, $projectResults);
                }
            }

            // Generate overall validation summary
            if (!empty($results['validation_results'])) {
                if ($syncHistory) {
                    $this->progressService->broadcastProgress(
                        $syncHistory,
                        "Generating validation summary...",
                        false,
                        ['progress_percentage' => 95]
                    );
                }

                $results['overall_validation_summary'] = $this->validationService->generateValidationSummary(
                    $results['validation_results']
                );
            }

            Log::info('Completed incremental worklog sync', [
                'projects_processed' => count($results['projects_processed']),
                'worklogs_processed' => $results['worklogs_processed'],
                'validation_summary' => $results['overall_validation_summary'],
            ]);

        } catch (Exception $e) {
            $errorMessage = 'Incremental worklog sync failed: ' . $e->getMessage();
            Log::error($errorMessage, ['exception' => $e]);
            $results['errors'][] = $errorMessage;
        }

        return $results;
    }

    /**
     * Sync worklogs for a specific project incrementally.
     */
    protected function syncProjectWorklogsIncremental(
        string $projectKey,
        ?Carbon $sinceDate,
        ?JiraSyncHistory $syncHistory
    ): array {
        $results = [
            'worklogs_processed' => 0,
            'worklogs_added' => 0,
            'worklogs_updated' => 0,
            'worklogs_skipped' => 0,
            'errors' => [],
        ];

        // Get or create project
        $jiraProject = JiraProject::where('project_key', $projectKey)->first();
        if (!$jiraProject) {
            $results['errors'][] = "Project {$projectKey} not found in local database. Run full sync first.";
            return $results;
        }

        // Determine last sync time
        $lastSyncTime = $sinceDate ?? $this->getLastWorklogSyncTime($projectKey);

        Log::info("Processing incremental worklog sync for project {$projectKey}", [
            'last_sync_time' => $lastSyncTime?->toISOString(),
            'using_provided_since_date' => $sinceDate !== null,
        ]);

        // Get issues that have worklogs updated since last sync
        $issuesWithUpdatedWorklogs = $this->getIssuesWithUpdatedWorklogs(
            $projectKey,
            $lastSyncTime
        );

        Log::info("Found issues with potentially updated worklogs for project {$projectKey}", [
            'issues_count' => count($issuesWithUpdatedWorklogs),
            'last_sync_time' => $lastSyncTime?->toISOString(),
        ]);

        foreach ($issuesWithUpdatedWorklogs as $issueData) {
            $issueKey = $issueData['key'];
            
            try {
                // Get local issue
                $localIssue = JiraIssue::where('issue_key', $issueKey)->first();
                if (!$localIssue) {
                    Log::warning("Issue {$issueKey} not found in local database, skipping worklogs");
                    $results['worklogs_skipped']++;
                    continue;
                }

                // Fetch and process worklogs for this issue
                $worklogResults = $this->processIssueWorklogsIncremental(
                    $localIssue,
                    $lastSyncTime
                );

                $results['worklogs_processed'] += $worklogResults['processed'];
                $results['worklogs_added'] += $worklogResults['added'];
                $results['worklogs_updated'] += $worklogResults['updated'];
                $results['worklogs_skipped'] += $worklogResults['skipped'];

            } catch (Exception $e) {
                $errorMessage = "Error processing worklogs for issue {$issueKey}: " . $e->getMessage();
                Log::error($errorMessage);
                $results['errors'][] = $errorMessage;
            }
        }

        // Note: worklog sync status will be updated by main sync method with validation results
        return $results;
    }

    /**
     * Get issues that have worklogs updated since the specified date.
     */
    protected function getIssuesWithUpdatedWorklogs(string $projectKey, ?Carbon $sinceDate): array
    {
        try {
            // Build JQL to find issues with updated worklogs
            $jql = "project = '{$projectKey}' AND worklogDate is not EMPTY";
            
            if ($sinceDate) {
                $jqlDate = $sinceDate->format('Y-m-d H:i');
                // Note: JIRA doesn't have direct worklog update date filtering in JQL
                // We'll fetch all issues with worklogs and filter at worklog level
                $jql .= " AND updated >= '{$jqlDate}'";
            }

            $jql .= " ORDER BY updated DESC";

            Log::debug("Fetching issues with updated worklogs", [
                'project' => $projectKey,
                'jql' => $jql,
            ]);

            return $this->jiraApiService->searchIssues($jql, 0, 1000)['issues'] ?? [];

        } catch (Exception $e) {
            Log::error("Failed to fetch issues with updated worklogs for {$projectKey}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Process worklogs for a specific issue incrementally.
     */
    protected function processIssueWorklogsIncremental(
        JiraIssue $localIssue,
        ?Carbon $lastSyncTime
    ): array {
        $results = [
            'processed' => 0,
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        try {
            // Fetch all worklogs for the issue
            $worklogsData = $this->jiraApiService->getWorklogsForIssue($localIssue->issue_key);
            
            Log::debug("Processing worklogs for issue {$localIssue->issue_key}", [
                'total_worklogs_from_jira' => count($worklogsData),
                'last_sync_time' => $lastSyncTime?->toISOString(),
            ]);

            foreach ($worklogsData as $worklogData) {
                $results['processed']++;

                // Skip if worklog hasn't been updated since last sync
                if ($lastSyncTime && !$this->isWorklogUpdatedSince($worklogData, $lastSyncTime)) {
                    $results['skipped']++;
                    continue;
                }

                // Process the worklog
                $worklogResult = $this->processWorklogData($worklogData, $localIssue);
                
                if ($worklogResult['action'] === 'added') {
                    $results['added']++;
                } elseif ($worklogResult['action'] === 'updated') {
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            }

        } catch (Exception $e) {
            Log::error("Failed to process worklogs for issue {$localIssue->issue_key}: " . $e->getMessage());
            throw $e;
        }

        Log::debug("Completed processing worklogs for issue {$localIssue->issue_key}", [
            'processed' => $results['processed'],
            'added' => $results['added'], 
            'updated' => $results['updated'],
            'skipped' => $results['skipped'],
            'efficiency_percent' => $results['processed'] > 0 ? round((($results['added'] + $results['updated']) / $results['processed']) * 100, 1) : 0,
        ]);

        return $results;
    }

    /**
     * Process individual worklog data.
     */
    protected function processWorklogData(array $worklogData, JiraIssue $localIssue): array
    {
        try {
            $jiraWorklogId = $worklogData['id'];
            
            // Get or create author
            $authorData = Arr::get($worklogData, 'author');
            if (!$authorData) {
                Log::warning("Skipping worklog {$jiraWorklogId} - no author data");
                return ['action' => 'skipped'];
            }

            $localAuthor = $this->getOrCreateUser($authorData);
            if (!$localAuthor) {
                Log::warning("Skipping worklog {$jiraWorklogId} - failed to create user");
                return ['action' => 'skipped'];
            }

            // Check if worklog already exists
            $existingWorklog = JiraWorklog::where('jira_worklog_id', $jiraWorklogId)->first();

            // Determine resource type
            $resourceType = $this->determineResourceType($worklogData);

            // Prepare worklog attributes
            $worklogAttributes = [
                'jira_worklog_id' => $jiraWorklogId,
                'jira_issue_id' => $localIssue->id,
                'jira_app_user_id' => $localAuthor->id,
                'time_spent_seconds' => $worklogData['timeSpentSeconds'],
                'started_at' => Carbon::parse($worklogData['started']),
                'resource_type' => $resourceType,
                'created_at' => isset($worklogData['created']) ? Carbon::parse($worklogData['created']) : now(),
                'updated_at' => isset($worklogData['updated']) ? Carbon::parse($worklogData['updated']) : now(),
            ];

            // Handle comment (can be string or array)
            if (isset($worklogData['comment'])) {
                $worklogAttributes['comment'] = is_array($worklogData['comment']) 
                    ? $this->extractTextFromJiraContent($worklogData['comment'])
                    : $worklogData['comment'];
            }

            if ($existingWorklog) {
                // Update existing worklog (JIRA as source of truth)
                $existingWorklog->update($worklogAttributes);
                return ['action' => 'updated', 'worklog' => $existingWorklog];
            } else {
                // Create new worklog
                $newWorklog = JiraWorklog::create($worklogAttributes);
                return ['action' => 'added', 'worklog' => $newWorklog];
            }

        } catch (Exception $e) {
            Log::error("Failed to process worklog data: " . $e->getMessage(), [
                'worklog_data' => $worklogData,
                'issue_id' => $localIssue->id,
            ]);
            throw $e;
        }
    }

    /**
     * Check if worklog has been updated since the specified date.
     */
    protected function isWorklogUpdatedSince(array $worklogData, Carbon $sinceDate): bool
    {
        $worklogUpdated = isset($worklogData['updated']) 
            ? Carbon::parse($worklogData['updated']) 
            : (isset($worklogData['created']) ? Carbon::parse($worklogData['created']) : null);
        
        return $worklogUpdated && $worklogUpdated->gte($sinceDate);
    }

    /**
     * Get or create user from JIRA user data.
     */
    protected function getOrCreateUser(array $userData): ?JiraAppUser
    {
        try {
            $accountId = $userData['accountId'] ?? null;
            $displayName = $userData['displayName'] ?? 'Unknown User';
            $emailAddress = $userData['emailAddress'] ?? null;

            if (!$accountId) {
                Log::warning('User data missing accountId', $userData);
                return null;
            }

            return JiraAppUser::firstOrCreate(
                ['account_id' => $accountId],
                [
                    'display_name' => $displayName,
                    'email_address' => $emailAddress,
                    'active' => $userData['active'] ?? true,
                ]
            );

        } catch (Exception $e) {
            Log::error('Failed to create user: ' . $e->getMessage(), $userData);
            return null;
        }
    }

    /**
     * Determine resource type based on worklog comment and context.
     */
    protected function determineResourceType(array $worklogData): string
    {
        $comment = $worklogData['comment'] ?? '';
        
        // Handle different comment formats (string vs JIRA rich text)
        if (is_array($comment)) {
            $comment = $this->extractTextFromJiraContent($comment);
        }

        $comment = strtolower($comment);

        // Priority-based matching
        foreach ($this->resourceTypeMapping as $type => $config) {
            foreach ($config['keywords'] as $keyword) {
                if (strpos($comment, strtolower($keyword)) !== false) {
                    return $type;
                }
            }
        }

        return 'general'; // Default fallback
    }

    /**
     * Extract text content from JIRA rich text format.
     */
    protected function extractTextFromJiraContent(array $content): string
    {
        if (isset($content['content']) && is_array($content['content'])) {
            $text = '';
            foreach ($content['content'] as $block) {
                if (isset($block['content']) && is_array($block['content'])) {
                    foreach ($block['content'] as $inline) {
                        if (isset($inline['text'])) {
                            $text .= $inline['text'] . ' ';
                        }
                    }
                }
            }
            return trim($text);
        }

        return is_string($content) ? $content : '';
    }

    /**
     * Get the last worklog sync time for a project.
     */
    protected function getLastWorklogSyncTime(string $projectKey): ?Carbon
    {
        $syncStatus = JiraWorklogSyncStatus::where('project_key', $projectKey)->first();
        return $syncStatus?->last_sync_at;
    }

    /**
     * Update worklog sync status for a project.
     */
    protected function updateWorklogSyncStatus(string $projectKey, array $results, ?array $validationResults = null): void
    {
        try {
            $metadata = [
                'sync_date' => now()->toISOString(),
                'results' => $results,
                'sync_duration_seconds' => 0, // Will be calculated by calling service
                'resource_type_distribution' => [],
            ];

            // Add validation metadata if available
            if ($validationResults) {
                $metadata['validation'] = [
                    'validation_passed' => $validationResults['validation_passed'],
                    'completeness_score' => $validationResults['sync_completeness_score'],
                    'discrepancy_percentage' => $validationResults['discrepancy_percentage'],
                    'data_quality_score' => $validationResults['data_quality_score'] ?? null,
                    'resource_type_distribution' => $validationResults['resource_type_distribution'] ?? [],
                    'validation_timestamp' => now()->toISOString(),
                ];
            }

            // Get current resource type distribution
            try {
                $distribution = JiraWorklog::whereHas('issue.project', function ($q) use ($projectKey) {
                    $q->where('project_key', $projectKey);
                })->select('resource_type', DB::raw('count(*) as count'))
                ->groupBy('resource_type')
                ->pluck('count', 'resource_type')
                ->toArray();

                $metadata['resource_type_distribution'] = $distribution;
            } catch (Exception $e) {
                Log::warning("Failed to get resource type distribution for {$projectKey}: " . $e->getMessage());
            }

            JiraWorklogSyncStatus::updateOrCreate(
                ['project_key' => $projectKey],
                [
                    'last_sync_at' => now(),
                    'last_sync_status' => empty($results['errors']) ? 'completed' : 'completed_with_errors',
                    'worklogs_processed' => $results['worklogs_processed'],
                    'worklogs_added' => $results['worklogs_added'],
                    'worklogs_updated' => $results['worklogs_updated'],
                    'last_error' => empty($results['errors']) ? null : implode('; ', array_slice($results['errors'], 0, 3)),
                    'sync_metadata' => $metadata,
                ]
            );

        } catch (Exception $e) {
            Log::error("Failed to update worklog sync status for {$projectKey}: " . $e->getMessage());
        }
    }

    /**
     * Get worklog sync statistics for projects.
     */
    public function getWorklogSyncStats(array $projectKeys = []): array
    {
        $query = JiraWorklogSyncStatus::query();
        
        if (!empty($projectKeys)) {
            $query->whereIn('project_key', $projectKeys);
        }

        $stats = $query->get();

        return [
            'total_projects' => $stats->count(),
            'recent_syncs' => $stats->where('last_sync_at', '>=', now()->subHours(24))->count(),
            'total_worklogs_processed' => $stats->sum('worklogs_processed'),
            'total_worklogs_added' => $stats->sum('worklogs_added'),
            'total_worklogs_updated' => $stats->sum('worklogs_updated'),
            'projects_with_errors' => $stats->whereNotNull('last_error')->count(),
            'last_sync_time' => $stats->max('last_sync_at'),
        ];
    }
}