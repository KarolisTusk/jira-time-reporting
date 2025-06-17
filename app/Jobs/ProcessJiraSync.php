<?php

namespace App\Jobs;

use App\Events\JiraSyncProgress;
use App\Models\JiraSyncHistory;
use App\Models\JiraSyncLog;
use App\Services\JiraImportService;
use App\Services\JiraSyncProgressService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessJiraSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400; // 4 hours timeout (FIXED: prevent incomplete large syncs)

    public int $tries = 5; // Retry up to 5 times

    public int $backoff = 60; // Wait 60 seconds between retries

    protected JiraSyncHistory $syncHistory;

    /**
     * Create a new job instance.
     */
    public function __construct(JiraSyncHistory $syncHistory)
    {
        $this->syncHistory = $syncHistory;
    }

    /**
     * Execute the job.
     */
    public function handle(JiraImportService $jiraImportService, JiraSyncProgressService $progressService): void
    {
        try {
            // Mark sync as started
            $this->syncHistory->markAsStarted();

            JiraSyncLog::info(
                $this->syncHistory->id,
                'JIRA sync process started',
                ['sync_type' => $this->syncHistory->sync_type]
            );

            // Broadcast initial progress
            broadcast(new JiraSyncProgress($this->syncHistory))->toOthers();

            // Execute the import process with progress tracking
            $result = $this->executeImportWithProgress($jiraImportService, $progressService);

            if ($result['success']) {
                $this->syncHistory->markAsCompleted();

                JiraSyncLog::info(
                    $this->syncHistory->id,
                    'JIRA sync process completed successfully',
                    $result
                );
            } else {
                $this->handleSyncFailure($result);
            }

            // Broadcast final progress
            broadcast(new JiraSyncProgress($this->syncHistory))->toOthers();

        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Safely update job progress.
     */
    protected function updateJobProgress(int $percentage): void
    {
        // Only attempt to set progress if the job instance exists and has the method
        if ($this->job && method_exists($this->job, 'setProgress')) {
            $this->job->setProgress($percentage);
        }
    }

    /**
     * Execute the import process with progress tracking.
     */
    protected function executeImportWithProgress(
        JiraImportService $jiraImportService,
        JiraSyncProgressService $progressService
    ): array {
        $progressService->setSyncHistory($this->syncHistory);
        $progressService->setCurrentOperation('Checking JIRA settings');

        // Initialize Laravel job progress
        $this->updateJobProgress(0);

        $results = [
            'success' => true,
            'message' => '',
            'projects_processed' => 0,
            'issues_processed' => 0,
            'worklogs_imported' => 0,
            'users_processed' => 0,
            'errors' => [],
        ];

        // Check JIRA settings
        $settings = \App\Models\JiraSetting::first();
        if (! $settings || empty($settings->project_keys)) {
            $message = 'JIRA settings not configured or no project keys specified.';
            $progressService->logError($message);
            $this->updateJobProgress(100); // Mark as complete with error

            return [
                'success' => false,
                'message' => $message,
                'errors' => [$message],
            ] + $results;
        }

        $progressService->setCurrentOperation('Fetching projects from JIRA');
        $this->updateJobProgress(5);

        try {
            // Fetch project details from JIRA API
            $jiraApiService = app(\App\Services\JiraApiService::class);
            $jiraProjectsData = $jiraApiService->getProjects($settings->project_keys);
            $validProjectKeys = [];

            $progressService->setProjectTotals(count($jiraProjectsData));
            $projectsProcessed = 0;

            // Calculate progress weights
            $totalProjects = count($jiraProjectsData);
            $projectWeight = 20; // 20% for projects
            $issueWeight = 40;   // 40% for issues
            $worklogWeight = 35; // 35% for worklogs
            // 5% already used for initial setup

            // Process each project
            foreach ($jiraProjectsData as $index => $projectDataFromApi) {
                try {
                    $progressService->setCurrentOperation("Processing project: {$projectDataFromApi['key']}");
                    $progressService->logInfo(
                        'Starting project processing',
                        ['project_key' => $projectDataFromApi['key']],
                        'project',
                        $projectDataFromApi['key'],
                        'fetch'
                    );

                    $project = $jiraImportService->storeProject($projectDataFromApi);
                    $validProjectKeys[] = $project->project_key;
                    $projectsProcessed++;

                    // Update Laravel job progress for projects (5% to 25%)
                    $projectProgress = 5 + ($projectWeight * ($index + 1) / $totalProjects);
                    $this->updateJobProgress((int) $projectProgress);

                    $progressService->updateProjectProgress($projectsProcessed, $project->project_key);
                    $progressService->logInfo(
                        'Successfully processed and stored project',
                        ['project_key' => $project->project_key],
                        'project',
                        $project->project_key,
                        'create'
                    );

                } catch (\Exception $e) {
                    $key = $projectDataFromApi['key'] ?? 'unknown';
                    $errorMessage = "Error storing project {$key}: ".$e->getMessage();
                    $progressService->logError($errorMessage, [
                        'projectData' => $projectDataFromApi,
                        'exception' => $e->getMessage(),
                    ], 'project', $key);
                    $results['errors'][] = $errorMessage;
                }
            }

            $results['projects_processed'] = $projectsProcessed;

            // Process issues and worklogs for each valid project
            $totalIssuesProcessed = 0;
            $totalWorklogsImported = 0;
            $totalUsersProcessed = 0;
            $totalValidProjects = count($validProjectKeys);
            $currentProjectIndex = 0;

            foreach ($validProjectKeys as $projectKey) {
                try {
                    $progressService->setCurrentOperation("Fetching issues for project: {$projectKey}");

                    $localProject = \App\Models\JiraProject::where('project_key', $projectKey)->first();
                    if (! $localProject) {
                        $errorMessage = "Local project {$projectKey} not found after attempting to store it. Skipping issues.";
                        $progressService->logError($errorMessage, [], 'project', $projectKey);
                        $results['errors'][] = $errorMessage;

                        continue;
                    }

                    // Fetch issues for the project with optimized chunking
                    $jiraApiService = app(\App\Services\JiraApiService::class);
                    
                    // Use chunked processing for large datasets to prevent memory issues
                    $issueOptions = [
                        'fields' => ['key', 'summary', 'status', 'assignee', 'project', 'issuetype', 'created', 'updated', 'timetracking'],
                        'maxResults' => 50, // Smaller chunks for memory efficiency
                    ];
                    
                    $issuesData = $jiraApiService->getIssuesForProject($projectKey, $issueOptions);

                    $progressService->setIssueTotals(count($issuesData), $projectKey);
                    $issuesProcessedForProject = 0;
                    $worklogsForProject = 0;
                    $totalIssuesInProject = count($issuesData);

                    // Process issues in chunks to manage memory
                    $issueChunks = array_chunk($issuesData, 10); // Process 10 issues at a time
                    $chunkIndex = 0;

                    foreach ($issueChunks as $issueChunk) {
                        $chunkIndex++;
                        $progressService->logInfo(
                            "Processing issue chunk {$chunkIndex} of " . count($issueChunks) . " for project {$projectKey}",
                            ['chunk_size' => count($issueChunk)],
                            'project',
                            $projectKey
                        );

                        foreach ($issueChunk as $issueIndex => $issueDataFromApi) {
                            try {
                                $progressService->setCurrentOperation("Processing issue: {$issueDataFromApi['key']}");

                                // Process assignee user
                                $assigneeData = \Illuminate\Support\Arr::get($issueDataFromApi, 'fields.assignee');
                                $localAssignee = null;
                                if ($assigneeData) {
                                    $localAssignee = $jiraImportService->storeUser($assigneeData, $results);
                                    if ($localAssignee) {
                                        $totalUsersProcessed++;
                                        $progressService->updateUserProgress($totalUsersProcessed);
                                    }
                                }

                                // Store issue
                                $localIssue = $jiraImportService->storeIssue(
                                    $issueDataFromApi,
                                    $localProject->id,
                                    $localAssignee ? $localAssignee->id : null
                                );

                                $issuesProcessedForProject++;
                                $totalIssuesProcessed++;

                                // Update Laravel job progress for issues (25% to 65%)
                                $globalIssueIndex = (($chunkIndex - 1) * 10) + array_search($issueDataFromApi, $issueChunk);
                                $issueProgress = 25 + ($issueWeight * ($currentProjectIndex + ($globalIssueIndex + 1) / $totalIssuesInProject) / $totalValidProjects);
                                $this->updateJobProgress((int) $issueProgress);

                                $progressService->updateIssueProgress($totalIssuesProcessed);

                                $progressService->logInfo(
                                    'Successfully processed issue',
                                    ['issue_key' => $localIssue->issue_key],
                                    'issue',
                                    $localIssue->issue_key,
                                    'create'
                                );

                                // Fetch and process worklogs for the issue (limit to recent worklogs for performance)
                                $progressService->setCurrentOperation("Processing worklogs for issue: {$localIssue->issue_key}");
                                try {
                                    $worklogsData = $jiraApiService->getWorklogsForIssue($localIssue->issue_key);
                                    
                                    // Limit worklogs processing to prevent excessive API calls
                                    $recentWorklogs = array_slice($worklogsData, 0, 100); // Limit to 100 most recent worklogs
                                    
                                    foreach ($recentWorklogs as $worklogDataFromApi) {
                                        try {
                                            // Process worklog author
                                            $authorData = \Illuminate\Support\Arr::get($worklogDataFromApi, 'author');
                                            $localAuthor = null;
                                            if ($authorData) {
                                                $localAuthor = $jiraImportService->storeUser($authorData, $results);
                                                if ($localAuthor) {
                                                    $totalUsersProcessed++;
                                                    $progressService->updateUserProgress($totalUsersProcessed);
                                                }
                                            }

                                            if ($localAuthor) {
                                                $jiraImportService->storeWorklog(
                                                    $worklogDataFromApi,
                                                    $localIssue->id,
                                                    $localAuthor->id
                                                );
                                                $worklogsForProject++;
                                                $totalWorklogsImported++;

                                                $progressService->updateWorklogProgress($totalWorklogsImported, $localIssue->issue_key);

                                                $progressService->logInfo(
                                                    'Successfully processed worklog',
                                                    [
                                                        'worklog_id' => $worklogDataFromApi['id'],
                                                        'issue_key' => $localIssue->issue_key,
                                                    ],
                                                    'worklog',
                                                    $worklogDataFromApi['id'],
                                                    'create'
                                                );
                                            } else {
                                                $progressService->logWarning(
                                                    'Skipping worklog due to missing author data',
                                                    ['worklogData' => $worklogDataFromApi],
                                                    'worklog',
                                                    $worklogDataFromApi['id'] ?? 'unknown'
                                                );
                                            }

                                        } catch (\Exception $e) {
                                            $wlId = $worklogDataFromApi['id'] ?? 'unknown';
                                            $errorMessage = "Error processing worklog ID {$wlId} for issue {$localIssue->issue_key}: ".$e->getMessage();
                                            $progressService->logError(
                                                $errorMessage,
                                                ['worklogData' => $worklogDataFromApi, 'exception' => $e->getMessage()],
                                                'worklog',
                                                $wlId
                                            );
                                            $results['errors'][] = $errorMessage;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    $progressService->logWarning(
                                        "Failed to fetch worklogs for issue {$localIssue->issue_key}: " . $e->getMessage(),
                                        ['exception' => $e->getMessage()],
                                        'issue',
                                        $localIssue->issue_key
                                    );
                                }

                            } catch (\Exception $e) {
                                $issueKeyApi = $issueDataFromApi['key'] ?? 'unknown';
                                $errorMessage = "Error processing issue {$issueKeyApi}: ".$e->getMessage();
                                $progressService->logError(
                                    $errorMessage,
                                    ['issueData' => $issueDataFromApi, 'exception' => $e->getMessage()],
                                    'issue',
                                    $issueKeyApi
                                );
                                $results['errors'][] = $errorMessage;
                            }
                        }

                        // Force garbage collection after each chunk to manage memory
                        gc_collect_cycles();
                        
                        // Memory usage monitoring
                        $memoryUsage = memory_get_usage(true);
                        $memoryLimit = ini_get('memory_limit');
                        $progressService->logInfo(
                            "Memory usage after chunk {$chunkIndex}: " . 
                            number_format($memoryUsage / 1024 / 1024, 2) . " MB (limit: {$memoryLimit})",
                            ['memory_usage_bytes' => $memoryUsage],
                            'system',
                            'memory'
                        );
                    }

                    $currentProjectIndex++;

                    // Update progress for completed project worklogs (65% to 100%)
                    $worklogProgress = 65 + ($worklogWeight * $currentProjectIndex / $totalValidProjects);
                    $this->updateJobProgress((int) $worklogProgress);

                    $progressService->logInfo(
                        'Successfully processed all issues and worklogs for project',
                        [
                            'project_key' => $projectKey,
                            'issues_processed' => $issuesProcessedForProject,
                            'worklogs_processed' => $worklogsForProject,
                        ],
                        'project',
                        $projectKey
                    );

                } catch (\Exception $e) {
                    $errorMessage = "Error processing project {$projectKey} (fetching issues/worklogs): ".$e->getMessage();
                    $progressService->logError(
                        $errorMessage,
                        ['exception' => $e->getMessage()],
                        'project',
                        $projectKey
                    );
                    $results['errors'][] = $errorMessage;
                    $results['success'] = false;
                }
            }

            $results['issues_processed'] = $totalIssuesProcessed;
            $results['worklogs_imported'] = $totalWorklogsImported;
            $results['users_processed'] = $totalUsersProcessed;

            // Final status
            if (empty($results['errors'])) {
                $results['message'] = 'JIRA data import completed successfully.';
                $progressService->setCurrentOperation('Import completed successfully');
                $this->updateJobProgress(100);
            } else {
                $results['message'] = 'JIRA data import completed with '.count($results['errors']).' error(s).';
                $results['success'] = false;
                $progressService->setCurrentOperation('Import completed with errors');
                $this->updateJobProgress(100);
            }

        } catch (\Exception $e) {
            $errorMessage = 'Critical error during JIRA import: '.$e->getMessage();
            $progressService->logError(
                $errorMessage,
                ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            $results['errors'][] = $errorMessage;
            $results['success'] = false;
            $results['message'] = $errorMessage;
            $this->updateJobProgress(100); // Mark as complete with error
        }

        return $results;
    }

    /**
     * Handle sync failure.
     */
    protected function handleSyncFailure(array $result): void
    {
        $errorDetails = [
            'message' => $result['message'] ?? 'Unknown error',
            'errors' => $result['errors'] ?? [],
            'attempt' => $this->attempts(),
        ];

        $this->syncHistory->markAsFailed($errorDetails);

        JiraSyncLog::error(
            $this->syncHistory->id,
            'JIRA sync process failed: '.($result['message'] ?? 'Unknown error'),
            $errorDetails
        );
    }

    /**
     * Handle job exception.
     */
    protected function handleException(Exception $e): void
    {
        $errorDetails = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'attempt' => $this->attempts(),
        ];

        $this->syncHistory->markAsFailed($errorDetails);

        JiraSyncLog::error(
            $this->syncHistory->id,
            'JIRA sync process failed with exception: '.$e->getMessage(),
            $errorDetails
        );

        Log::error('JIRA sync job failed', [
            'sync_history_id' => $this->syncHistory->id,
            'exception' => $e,
        ]);

        // Re-throw the exception to trigger Laravel's failed job handling
        throw $e;
    }

    /**
     * The job failed to process.
     */
    public function failed(?Throwable $exception): void
    {
        $this->syncHistory->markAsFailed([
            'exception' => $exception?->getMessage() ?? 'Unknown error',
            'trace' => $exception?->getTraceAsString() ?? 'No trace available',
        ]);

        JiraSyncLog::error(
            $this->syncHistory->id,
            'JIRA sync job failed permanently after all retry attempts',
            [
                'exception' => $exception?->getMessage() ?? 'Unknown error',
                'attempts_made' => $this->attempts ?? 1,
                'max_attempts' => $this->tries,
            ]
        );

        Log::error('JIRA sync job failed permanently', [
            'sync_history_id' => $this->syncHistory->id,
            'exception' => $exception?->getMessage() ?? 'Unknown error',
            'attempts' => $this->attempts ?? 1,
        ]);

        // Broadcast failure event
        try {
            broadcast(new JiraSyncProgress($this->syncHistory))->toOthers();
        } catch (Exception $e) {
            Log::warning('Failed to broadcast sync failure: ' . $e->getMessage());
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     * Implements exponential backoff with jitter to avoid thundering herd.
     */
    public function backoff(): array
    {
        // Exponential backoff: 30s, 60s, 120s, 240s, 300s (max 5 minutes)
        return [30, 60, 120, 240, 300];
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'jira-sync',
            'sync-history:'.$this->syncHistory->id,
            'user:'.$this->syncHistory->triggered_by,
        ];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'jira-sync-'.$this->syncHistory->id;
    }
}
