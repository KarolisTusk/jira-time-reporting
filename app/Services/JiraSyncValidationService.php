<?php

namespace App\Services;

use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraWorklog;
use App\Models\JiraSyncHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;

class JiraSyncValidationService
{
    protected JiraApiServiceV3 $jiraApiService;

    public function __construct(JiraApiServiceV3 $jiraApiService)
    {
        $this->jiraApiService = $jiraApiService;
    }

    /**
     * Validate sync completeness by comparing local data with JIRA API counts.
     */
    public function validateSyncCompleteness(JiraSyncHistory $syncHistory): array
    {
        $projectKeys = $syncHistory->project_keys ?? [];
        $validation = [
            'overall_status' => 'valid',
            'total_discrepancy_percent' => 0.0,
            'projects' => [],
            'summary' => [
                'total_projects' => count($projectKeys),
                'valid_projects' => 0,
                'invalid_projects' => 0,
                'total_issues_local' => 0,
                'total_issues_jira' => 0,
                'total_worklogs_local' => 0,
                'total_worklogs_jira' => 0,
            ]
        ];

        foreach ($projectKeys as $projectKey) {
            $projectValidation = $this->validateProjectSync($projectKey, $syncHistory);
            $validation['projects'][$projectKey] = $projectValidation;

            // Update summary
            $validation['summary']['total_issues_local'] += $projectValidation['local_issue_count'];
            $validation['summary']['total_issues_jira'] += $projectValidation['jira_issue_count'];
            $validation['summary']['total_worklogs_local'] += $projectValidation['local_worklog_count'];
            $validation['summary']['total_worklogs_jira'] += $projectValidation['jira_worklog_count'];

            if ($projectValidation['status'] === 'valid') {
                $validation['summary']['valid_projects']++;
            } else {
                $validation['summary']['invalid_projects']++;
                $validation['overall_status'] = 'invalid';
            }
        }

        // Calculate overall discrepancy percentage
        if ($validation['summary']['total_issues_jira'] > 0) {
            $issueDiscrepancy = abs($validation['summary']['total_issues_local'] - $validation['summary']['total_issues_jira']);
            $validation['total_discrepancy_percent'] = ($issueDiscrepancy / $validation['summary']['total_issues_jira']) * 100;
        }

        // Mark as invalid if discrepancy exceeds threshold
        $maxDiscrepancy = config('jira.max_discrepancy_percent', 5.0);
        if ($validation['total_discrepancy_percent'] > $maxDiscrepancy) {
            $validation['overall_status'] = 'invalid';
        }

        Log::info('JIRA Sync Validation Complete', $validation);

        return $validation;
    }

    /**
     * Validate sync for a specific project.
     */
    protected function validateProjectSync(string $projectKey, JiraSyncHistory $syncHistory): array
    {
        try {
            // Get local counts
            $jiraProject = JiraProject::where('project_key', $projectKey)->first();
            if (!$jiraProject) {
                return [
                    'status' => 'error',
                    'error' => 'Project not found in local database',
                    'local_issue_count' => 0,
                    'local_worklog_count' => 0,
                    'jira_issue_count' => 0,
                    'jira_worklog_count' => 0,
                ];
            }

            $localIssueCount = JiraIssue::where('jira_project_id', $jiraProject->id)->count();
            $localWorklogCount = JiraWorklog::whereHas('jiraIssue', function ($query) use ($jiraProject) {
                $query->where('jira_project_id', $jiraProject->id);
            })->count();

            // Get JIRA API counts
            $jiraIssueCounts = $this->getJiraIssueCounts($projectKey);
            $jiraWorklogCounts = $this->getJiraWorklogCounts($projectKey);

            // Calculate discrepancies
            $issueDiscrepancy = abs($localIssueCount - $jiraIssueCounts);
            $worklogDiscrepancy = abs($localWorklogCount - $jiraWorklogCounts);

            $issueDiscrepancyPercent = $jiraIssueCounts > 0 ? ($issueDiscrepancy / $jiraIssueCounts) * 100 : 0;
            $worklogDiscrepancyPercent = $jiraWorklogCounts > 0 ? ($worklogDiscrepancy / $jiraWorklogCounts) * 100 : 0;

            $maxDiscrepancy = config('jira.max_discrepancy_percent', 5.0);
            $status = ($issueDiscrepancyPercent <= $maxDiscrepancy && $worklogDiscrepancyPercent <= $maxDiscrepancy) ? 'valid' : 'invalid';

            return [
                'status' => $status,
                'local_issue_count' => $localIssueCount,
                'local_worklog_count' => $localWorklogCount,
                'jira_issue_count' => $jiraIssueCounts,
                'jira_worklog_count' => $jiraWorklogCounts,
                'issue_discrepancy' => $issueDiscrepancy,
                'worklog_discrepancy' => $worklogDiscrepancy,
                'issue_discrepancy_percent' => round($issueDiscrepancyPercent, 2),
                'worklog_discrepancy_percent' => round($worklogDiscrepancyPercent, 2),
            ];

        } catch (Exception $e) {
            Log::error("Validation failed for project {$projectKey}: " . $e->getMessage());
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'local_issue_count' => 0,
                'local_worklog_count' => 0,
                'jira_issue_count' => 0,
                'jira_worklog_count' => 0,
            ];
        }
    }

    /**
     * Get issue count from JIRA API.
     */
    protected function getJiraIssueCounts(string $projectKey): int
    {
        try {
            $jql = "project = '{$projectKey}'";
            $searchResult = $this->jiraApiService->searchIssues($jql, 0, 1); // Only need total count
            return $searchResult['total'] ?? 0;
        } catch (Exception $e) {
            Log::error("Failed to get JIRA issue count for {$projectKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get worklog count from JIRA API (estimated).
     */
    protected function getJiraWorklogCounts(string $projectKey): int
    {
        try {
            // This is an approximation since JIRA doesn't provide direct worklog counts
            // We search for issues with worklogs and estimate based on sample
            $jql = "project = '{$projectKey}' AND worklogDate is not EMPTY";
            $searchResult = $this->jiraApiService->searchIssues($jql, 0, 10); // Sample first 10 issues
            $issuesWithWorklogs = $searchResult['total'] ?? 0;
            
            if ($issuesWithWorklogs === 0) {
                return 0;
            }

            // Sample worklog count from first few issues
            $sampleWorklogCount = 0;
            $sampleIssueCount = min(5, count($searchResult['issues'] ?? []));
            
            foreach (array_slice($searchResult['issues'] ?? [], 0, $sampleIssueCount) as $issue) {
                $issueKey = $issue['key'];
                $worklogs = $this->jiraApiService->getWorklogsForIssue($issueKey);
                $sampleWorklogCount += count($worklogs);
            }

            // Estimate total worklog count
            $avgWorklogsPerIssue = $sampleIssueCount > 0 ? $sampleWorklogCount / $sampleIssueCount : 0;
            return (int) round($issuesWithWorklogs * $avgWorklogsPerIssue);

        } catch (Exception $e) {
            Log::error("Failed to estimate JIRA worklog count for {$projectKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Validate data relationships and integrity.
     */
    public function validateDataIntegrity(string $projectKey): array
    {
        $issues = [
            'orphaned_worklogs' => [],
            'missing_users' => [],
            'invalid_dates' => [],
            'duplicate_entries' => [],
        ];

        // Check for orphaned worklogs (worklogs without issues)
        $orphanedWorklogs = JiraWorklog::whereDoesntHave('jiraIssue')->count();
        if ($orphanedWorklogs > 0) {
            $issues['orphaned_worklogs'] = [
                'count' => $orphanedWorklogs,
                'description' => 'Worklogs without corresponding issues'
            ];
        }

        // Check for missing user references
        $worklogsWithoutUsers = JiraWorklog::whereNull('jira_app_user_id')->count();
        if ($worklogsWithoutUsers > 0) {
            $issues['missing_users'] = [
                'count' => $worklogsWithoutUsers,
                'description' => 'Worklogs without user references'
            ];
        }

        // Check for invalid dates
        $invalidDates = JiraWorklog::where('started_at', '>', Carbon::now())->count();
        if ($invalidDates > 0) {
            $issues['invalid_dates'] = [
                'count' => $invalidDates,
                'description' => 'Worklogs with future start dates'
            ];
        }

        // Check for duplicate worklogs (same issue, user, start time)
        $duplicates = JiraWorklog::selectRaw('jira_issue_id, jira_app_user_id, started_at, COUNT(*) as count')
            ->groupBy('jira_issue_id', 'jira_app_user_id', 'started_at')
            ->having('count', '>', 1)
            ->count();

        if ($duplicates > 0) {
            $issues['duplicate_entries'] = [
                'count' => $duplicates,
                'description' => 'Duplicate worklog entries'
            ];
        }

        return [
            'status' => empty(array_filter($issues)) ? 'valid' : 'issues_found',
            'issues' => array_filter($issues),
        ];
    }

    /**
     * Generate validation report for sync history.
     */
    public function generateValidationReport(JiraSyncHistory $syncHistory): array
    {
        $validation = $this->validateSyncCompleteness($syncHistory);
        
        $report = [
            'sync_id' => $syncHistory->id,
            'validated_at' => Carbon::now()->toISOString(),
            'overall_status' => $validation['overall_status'],
            'summary' => $validation['summary'],
            'recommendations' => [],
        ];

        // Generate recommendations based on validation results
        if ($validation['overall_status'] === 'invalid') {
            if ($validation['total_discrepancy_percent'] > 10) {
                $report['recommendations'][] = 'Large data discrepancy detected. Consider re-running sync with extended timeout.';
            }

            if ($validation['summary']['invalid_projects'] > 0) {
                $report['recommendations'][] = 'Some projects have data discrepancies. Check individual project sync status.';
            }

            $report['recommendations'][] = 'Review sync logs for errors that might have caused incomplete data import.';
        }

        return $report;
    }
}