<?php

namespace App\Services;

use App\Models\JiraAppUser;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraSetting;
use App\Models\JiraWorklog;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException; // Added

class JiraImportService
{
    protected JiraApiService $jiraApiService;

    private array $projectSpecificUserCache = [];

    public function __construct(JiraApiService $jiraApiService)
    {
        $this->jiraApiService = $jiraApiService;
    }

    /**
     * Orchestrates the JIRA data import process for all configured projects.
     */
    public function importDataForAllConfiguredProjects(): array
    {
        Log::info('JIRA data import process started.');
        $startTime = microtime(true);
        $results = [
            'success' => true,
            'message' => '',
            'projects_processed' => 0,
            'issues_processed' => 0,
            'worklogs_imported' => 0,
            'users_processed' => 0, // Added for tracking users
            'errors' => [],
        ];

        $settings = JiraSetting::first();
        if (! $settings || empty($settings->project_keys)) {
            $results['success'] = false;
            $results['message'] = 'JIRA settings not configured or no project keys specified.';
            Log::warning('JIRA import skipped: '.$results['message']);

            return $results;
        }

        // Fetch project details from JIRA API to ensure we have up-to-date info
        // and to handle cases where a project might be in settings but not accessible/valid anymore.
        $jiraProjectsData = $this->jiraApiService->getProjects($settings->project_keys);
        $validProjectKeys = [];

        foreach ($jiraProjectsData as $projectDataFromApi) {
            try {
                $project = $this->storeProject($projectDataFromApi);
                $validProjectKeys[] = $project->project_key; // Store the key of successfully stored/updated projects
                Log::info("Successfully processed and stored project: {$project->project_key}");
            } catch (Exception $e) {
                $key = $projectDataFromApi['key'] ?? 'unknown';
                $errorMessage = "Error storing project {$key}: ".$e->getMessage();
                Log::error($errorMessage, ['projectData' => $projectDataFromApi]);
                $results['errors'][] = $errorMessage;
                // Continue to next project if one fails
            }
        }

        foreach ($validProjectKeys as $projectKey) {
            try {
                $localProject = JiraProject::where('project_key', $projectKey)->first();
                if (! $localProject) {
                    Log::error("Local project {$projectKey} not found after attempting to store it. Skipping issues.");
                    $results['errors'][] = "Local project {$projectKey} not found. Cannot import its issues.";

                    continue;
                }

                Log::info("Fetching issues for project: {$projectKey}");
                $issuesData = $this->jiraApiService->getIssuesForProject($projectKey, [
                    'fields' => 'key,summary,status,assignee,project,issuetype,created,updated,timetracking,worklog',
                    'maxResults' => 50, // Adjust as needed, service handles pagination
                ]);
                $results['projects_processed']++;

                foreach ($issuesData as $issueDataFromApi) {
                    try {
                        $assigneeData = Arr::get($issueDataFromApi, 'fields.assignee');
                        $localAssignee = $assigneeData ? $this->storeUser($assigneeData, $results) : null;

                        $localIssue = $this->storeIssue($issueDataFromApi, $localProject->id, $localAssignee ? $localAssignee->id : null);
                        $results['issues_processed']++;

                        // Fetch worklogs for the current issue
                        $worklogsData = $this->jiraApiService->getWorklogsForIssue($localIssue->issue_key);
                        foreach ($worklogsData as $worklogDataFromApi) {
                            try {
                                $authorData = Arr::get($worklogDataFromApi, 'author');
                                $localAuthor = $authorData ? $this->storeUser($authorData, $results) : null;

                                if ($localAuthor) { // Worklog must have an author
                                    $this->storeWorklog($worklogDataFromApi, $localIssue->id, $localAuthor->id);
                                    $results['worklogs_imported']++;
                                } else {
                                    Log::warning("Skipping worklog for issue {$localIssue->issue_key} due to missing author data.", ['worklogData' => $worklogDataFromApi]);
                                }
                            } catch (Exception $e) {
                                $wlId = $worklogDataFromApi['id'] ?? 'unknown';
                                $errorMessage = "Error processing worklog ID {$wlId} for issue {$localIssue->issue_key}: ".$e->getMessage();
                                Log::error($errorMessage, ['worklogData' => $worklogDataFromApi]);
                                $results['errors'][] = $errorMessage;
                            }
                        }
                    } catch (Exception $e) {
                        $issueKeyApi = $issueDataFromApi['key'] ?? 'unknown';
                        $errorMessage = "Error processing issue {$issueKeyApi}: ".$e->getMessage();
                        Log::error($errorMessage, ['issueData' => $issueDataFromApi]);
                        $results['errors'][] = $errorMessage;
                    }
                }
                Log::info("Successfully processed issues and worklogs for project: {$projectKey}");
            } catch (Exception $e) {
                $errorMessage = "Error processing project {$projectKey} (fetching issues/worklogs): ".$e->getMessage();
                Log::error($errorMessage);
                $results['errors'][] = $errorMessage;
                $results['success'] = false;
            }
        }

        $duration = microtime(true) - $startTime;
        if (empty($results['errors'])) {
            $results['message'] = 'JIRA data import completed successfully.';
        } else {
            $results['message'] = 'JIRA data import completed with '.count($results['errors']).' error(s).';
            $results['success'] = false; // Ensure success is false if there were errors
        }
        Log::info($results['message']." Duration: {$duration} seconds.", $results);

        return $results;
    }

    /**
     * Stores or updates a project based on API data.
     *
     * @throws InvalidArgumentException if required data is missing.
     */
    public function storeProject(array $projectData): JiraProject // Changed from protected
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

        return JiraProject::updateOrCreate(
            ['jira_id' => $projectData['id']],
            [
                'project_key' => $projectData['key'],
                'name' => $projectData['name'],
            ]
        );
    }

    /**
     * Stores or updates a JIRA application user based on API data.
     *
     * @param  array  $userData  Data from JIRA API for a user.
     * @param  array  $results  Reference to the results array to update \'users_processed\' count.
     * @return JiraAppUser|null The created or updated user, or null if essential data (accountId) is missing.
     *
     * @throws InvalidArgumentException if displayName is missing but accountId is present.
     */
    public function storeUser(array $userData, array &$results): ?JiraAppUser // Changed from protected
    {
        $accountId = $userData['accountId'] ?? null;

        if (empty($accountId)) {
            Log::warning('Skipping user creation/update due to missing accountId. This user cannot be reliably identified or stored.', ['userData' => $userData]);

            return null;
        }

        if (empty($userData['displayName'])) {
            throw new InvalidArgumentException('User data from API is missing the required displayName field for accountId: '.$accountId);
        }

        $user = JiraAppUser::updateOrCreate(
            ['jira_account_id' => $accountId],
            [
                'display_name' => $userData['displayName'],
                'email_address' => $userData['emailAddress'] ?? null,
            ]
        );
        $results['users_processed']++;

        return $user;
    }

    /**
     * Stores or updates an issue based on API data.
     *
     * @throws InvalidArgumentException if required data is missing.
     */
    public function storeIssue(array $issueData, int $jiraProjectId, ?int $assigneeJiraAppUserId): JiraIssue // Changed from protected
    {
        $jiraId = $issueData['id'] ?? null;

        if (empty($jiraId)) {
            throw new InvalidArgumentException('Issue data from API is missing the required id field.');
        }
        $fields = $issueData['fields'] ?? null;
        if (! $fields || ! isset($fields['summary'], $fields['status']['name'])) {
            $errorMessage = 'Missing required issue fields (summary or status.name) from API for issue key: '.$issueData['key'];
            Log::error($errorMessage, ['issueData' => $issueData]);
            throw new InvalidArgumentException($errorMessage);
        }

        $originalEstimateSeconds = Arr::get($fields, 'timetracking.originalEstimateSeconds');

        return JiraIssue::updateOrCreate(
            ['jira_id' => $issueData['id']],
            [
                'jira_project_id' => $jiraProjectId,
                'issue_key' => $issueData['key'],
                'summary' => $fields['summary'],
                'status' => $fields['status']['name'], // Assuming status is an object with a name property
                'assignee_jira_app_user_id' => $assigneeJiraAppUserId,
                'original_estimate_seconds' => $originalEstimateSeconds,
                // Add created_at and updated_at from JIRA if desired, converting them to Carbon instances
                // 'created_at' => Carbon::parse($fields['created']),
                // 'updated_at' => Carbon::parse($fields['updated']),
            ]
        );
    }

    /**
     * Stores or updates a worklog based on API data.
     *
     * @throws InvalidArgumentException if required data is missing.
     */
    public function storeWorklog(array $worklogData, int $jiraIssueId, int $authorJiraAppUserId): JiraWorklog // Changed from protected
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

        return JiraWorklog::updateOrCreate(
            ['jira_id' => $worklogData['id']],
            [
                'jira_issue_id' => $jiraIssueId,
                'jira_app_user_id' => $authorJiraAppUserId,
                'time_spent_seconds' => $worklogData['timeSpentSeconds'],
                'started_at' => Carbon::parse($worklogData['started']),
            ]
        );
    }
}
