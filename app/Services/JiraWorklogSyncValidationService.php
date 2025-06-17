<?php

namespace App\Services;

use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraWorklog;
use App\Models\JiraWorklogSyncStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JiraWorklogSyncValidationService
{
    protected JiraApiServiceV3 $jiraApiService;

    public function __construct(JiraApiServiceV3 $jiraApiService)
    {
        $this->jiraApiService = $jiraApiService;
    }

    /**
     * Validate worklog sync results for a project.
     */
    public function validateWorklogSyncResults(
        string $projectKey,
        array $syncResults,
        ?Carbon $sinceDate = null
    ): array {
        $validationResults = [
            'project_key' => $projectKey,
            'validation_passed' => true,
            'total_issues_checked' => 0,
            'total_worklogs_expected' => 0,
            'total_worklogs_found' => 0,
            'discrepancy_percentage' => 0.0,
            'missing_worklogs' => [],
            'extra_worklogs' => [],
            'validation_errors' => [],
            'validation_warnings' => [],
            'sync_completeness_score' => 100.0,
        ];

        try {
            Log::info("Starting worklog validation for project {$projectKey}", [
                'since_date' => $sinceDate?->toISOString(),
                'sync_results' => $syncResults,
            ]);

            // Get project from database
            $project = JiraProject::where('project_key', $projectKey)->first();
            if (!$project) {
                $validationResults['validation_errors'][] = "Project {$projectKey} not found in database";
                $validationResults['validation_passed'] = false;
                return $validationResults;
            }

            // Validate resource type distribution
            $resourceTypeValidation = $this->validateResourceTypeDistribution($projectKey, $sinceDate);
            $validationResults = array_merge($validationResults, $resourceTypeValidation);

            // Validate worklog data integrity
            $integrityValidation = $this->validateWorklogDataIntegrity($projectKey, $sinceDate);
            $validationResults['validation_errors'] = array_merge(
                $validationResults['validation_errors'],
                $integrityValidation['errors']
            );
            $validationResults['validation_warnings'] = array_merge(
                $validationResults['validation_warnings'],
                $integrityValidation['warnings']
            );

            // Sample-based JIRA API validation (for performance)
            $sampleValidation = $this->validateWorklogSampleAgainstJira($projectKey, $sinceDate);
            $validationResults = array_merge($validationResults, $sampleValidation);

            // Calculate overall validation score
            $validationResults['sync_completeness_score'] = $this->calculateCompletenessScore($validationResults);

            // Determine if validation passed
            $validationResults['validation_passed'] = empty($validationResults['validation_errors']) &&
                $validationResults['discrepancy_percentage'] <= config('jira.max_discrepancy_percent', 5.0);

            Log::info("Worklog validation completed for project {$projectKey}", [
                'passed' => $validationResults['validation_passed'],
                'completeness_score' => $validationResults['sync_completeness_score'],
                'discrepancy_percentage' => $validationResults['discrepancy_percentage'],
            ]);

        } catch (\Exception $e) {
            $errorMessage = "Worklog validation failed for {$projectKey}: " . $e->getMessage();
            Log::error($errorMessage, ['exception' => $e]);
            
            $validationResults['validation_errors'][] = $errorMessage;
            $validationResults['validation_passed'] = false;
            $validationResults['sync_completeness_score'] = 0.0;
        }

        return $validationResults;
    }

    /**
     * Validate resource type distribution for worklogs.
     */
    protected function validateResourceTypeDistribution(string $projectKey, ?Carbon $sinceDate): array
    {
        $results = [
            'resource_type_distribution' => [],
            'resource_type_warnings' => [],
        ];

        try {
            $query = JiraWorklog::whereHas('issue.project', function ($q) use ($projectKey) {
                $q->where('project_key', $projectKey);
            });

            if ($sinceDate) {
                $query->where('updated_at', '>=', $sinceDate);
            }

            $distribution = $query->select('resource_type', DB::raw('count(*) as count'))
                ->groupBy('resource_type')
                ->pluck('count', 'resource_type')
                ->toArray();

            $results['resource_type_distribution'] = $distribution;

            // Check for anomalies in resource type distribution
            $totalWorklogs = array_sum($distribution);
            if ($totalWorklogs > 0) {
                $generalPercentage = ($distribution['general'] ?? 0) / $totalWorklogs * 100;
                
                // Warn if too many worklogs are classified as 'general'
                if ($generalPercentage > 50) {
                    $results['resource_type_warnings'][] = 
                        "High percentage of 'general' resource type ({$generalPercentage}%) - consider improving classification keywords";
                }

                // Warn if no resource diversity
                if (count($distribution) === 1) {
                    $results['resource_type_warnings'][] = 
                        "All worklogs have the same resource type - classification may need improvement";
                }
            }

        } catch (\Exception $e) {
            $results['resource_type_warnings'][] = "Failed to validate resource type distribution: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Validate worklog data integrity.
     */
    protected function validateWorklogDataIntegrity(string $projectKey, ?Carbon $sinceDate): array
    {
        $results = [
            'errors' => [],
            'warnings' => [],
            'data_quality_score' => 100.0,
        ];

        try {
            $query = JiraWorklog::whereHas('issue.project', function ($q) use ($projectKey) {
                $q->where('project_key', $projectKey);
            });

            if ($sinceDate) {
                $query->where('updated_at', '>=', $sinceDate);
            }

            $worklogs = $query->get();
            $totalWorklogs = $worklogs->count();
            $issues = [];

            if ($totalWorklogs === 0) {
                $results['warnings'][] = "No worklogs found for validation";
                return $results;
            }

            foreach ($worklogs as $worklog) {
                // Check for missing required fields
                if (!$worklog->jira_worklog_id) {
                    $issues[] = "Worklog missing JIRA worklog ID";
                }

                if (!$worklog->time_spent_seconds || $worklog->time_spent_seconds <= 0) {
                    $issues[] = "Worklog with invalid time spent: {$worklog->time_spent_seconds}";
                }

                if (!$worklog->started_at) {
                    $issues[] = "Worklog missing start date";
                }

                if (!$worklog->jira_app_user_id) {
                    $issues[] = "Worklog missing user assignment";
                }

                // Check for reasonable time values (not more than 24 hours)
                if ($worklog->time_spent_seconds > 86400) { // 24 hours
                    $results['warnings'][] = "Worklog with unusually high time: " . 
                        round($worklog->time_spent_seconds / 3600, 2) . " hours";
                }

                // Check for future dates
                if ($worklog->started_at && $worklog->started_at->isFuture()) {
                    $results['warnings'][] = "Worklog with future start date: " . $worklog->started_at->toDateString();
                }
            }

            // Calculate data quality score
            $errorCount = count($issues);
            if ($totalWorklogs > 0) {
                $errorPercentage = ($errorCount / $totalWorklogs) * 100;
                $results['data_quality_score'] = max(0, 100 - $errorPercentage);
            }

            // Add critical errors if error rate is too high
            if ($errorCount > 0) {
                $results['errors'] = array_slice(array_unique($issues), 0, 10); // Limit to 10 unique errors
                
                if ($errorCount > $totalWorklogs * 0.1) { // More than 10% error rate
                    $results['errors'][] = "High error rate in worklog data: {$errorCount} errors out of {$totalWorklogs} worklogs";
                }
            }

        } catch (\Exception $e) {
            $results['errors'][] = "Data integrity validation failed: " . $e->getMessage();
            $results['data_quality_score'] = 0.0;
        }

        return $results;
    }

    /**
     * Validate a sample of worklogs against JIRA API.
     */
    protected function validateWorklogSampleAgainstJira(string $projectKey, ?Carbon $sinceDate): array
    {
        $results = [
            'total_issues_checked' => 0,
            'total_worklogs_expected' => 0,
            'total_worklogs_found' => 0,
            'discrepancy_percentage' => 0.0,
            'missing_worklogs' => [],
            'extra_worklogs' => [],
            'sample_validation_errors' => [],
        ];

        try {
            // Get a sample of issues to validate (max 10 for performance)
            $issuesQuery = JiraIssue::where('project_key', $projectKey);
            
            if ($sinceDate) {
                $issuesQuery->where('updated_at', '>=', $sinceDate);
            }

            $sampleIssues = $issuesQuery->inRandomOrder()->limit(10)->get();
            $results['total_issues_checked'] = $sampleIssues->count();

            if ($sampleIssues->isEmpty()) {
                $results['sample_validation_errors'][] = "No issues found for sample validation";
                return $results;
            }

            foreach ($sampleIssues as $issue) {
                try {
                    // Get worklogs from JIRA API
                    $jiraWorklogs = $this->jiraApiService->getWorklogsForIssue($issue->issue_key);
                    
                    // Filter by date if specified
                    if ($sinceDate) {
                        $jiraWorklogs = array_filter($jiraWorklogs, function ($worklog) use ($sinceDate) {
                            $worklogUpdated = isset($worklog['updated']) 
                                ? Carbon::parse($worklog['updated']) 
                                : Carbon::parse($worklog['created']);
                            return $worklogUpdated->gte($sinceDate);
                        });
                    }

                    $jiraWorklogIds = array_column($jiraWorklogs, 'id');
                    $results['total_worklogs_expected'] += count($jiraWorklogIds);

                    // Get local worklogs
                    $localWorklogsQuery = JiraWorklog::where('jira_issue_id', $issue->id);
                    if ($sinceDate) {
                        $localWorklogsQuery->where('updated_at', '>=', $sinceDate);
                    }
                    
                    $localWorklogs = $localWorklogsQuery->get();
                    $localWorklogIds = $localWorklogs->pluck('jira_worklog_id')->toArray();
                    $results['total_worklogs_found'] += count($localWorklogIds);

                    // Find discrepancies
                    $missingInLocal = array_diff($jiraWorklogIds, $localWorklogIds);
                    $extraInLocal = array_diff($localWorklogIds, $jiraWorklogIds);

                    if (!empty($missingInLocal)) {
                        $results['missing_worklogs'] = array_merge(
                            $results['missing_worklogs'],
                            array_map(fn($id) => "{$issue->issue_key}:{$id}", $missingInLocal)
                        );
                    }

                    if (!empty($extraInLocal)) {
                        $results['extra_worklogs'] = array_merge(
                            $results['extra_worklogs'],
                            array_map(fn($id) => "{$issue->issue_key}:{$id}", $extraInLocal)
                        );
                    }

                    // Throttle API requests
                    usleep(100000); // 0.1 second delay

                } catch (\Exception $e) {
                    $results['sample_validation_errors'][] = 
                        "Failed to validate issue {$issue->issue_key}: " . $e->getMessage();
                }
            }

            // Calculate discrepancy percentage
            if ($results['total_worklogs_expected'] > 0) {
                $totalDiscrepancies = count($results['missing_worklogs']) + count($results['extra_worklogs']);
                $results['discrepancy_percentage'] = 
                    ($totalDiscrepancies / $results['total_worklogs_expected']) * 100;
            }

        } catch (\Exception $e) {
            $results['sample_validation_errors'][] = "Sample validation failed: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Calculate sync completeness score.
     */
    protected function calculateCompletenessScore(array $validationResults): float
    {
        $score = 100.0;

        // Deduct points for discrepancies
        $score -= $validationResults['discrepancy_percentage'] * 2; // 2 points per 1% discrepancy

        // Deduct points for errors
        $errorCount = count($validationResults['validation_errors']);
        $score -= $errorCount * 10; // 10 points per error

        // Deduct points for warnings
        $warningCount = count($validationResults['validation_warnings']) + 
                       count($validationResults['resource_type_warnings'] ?? []);
        $score -= $warningCount * 2; // 2 points per warning

        // Include data quality score if available
        if (isset($validationResults['data_quality_score'])) {
            $score = ($score + $validationResults['data_quality_score']) / 2;
        }

        return max(0.0, min(100.0, round($score, 1)));
    }

    /**
     * Generate validation summary for multiple projects.
     */
    public function generateValidationSummary(array $projectValidations): array
    {
        $summary = [
            'total_projects' => count($projectValidations),
            'projects_passed' => 0,
            'projects_failed' => 0,
            'average_completeness_score' => 0.0,
            'total_errors' => 0,
            'total_warnings' => 0,
            'overall_discrepancy_percentage' => 0.0,
            'critical_issues' => [],
            'recommendations' => [],
        ];

        if (empty($projectValidations)) {
            return $summary;
        }

        $totalCompletenessScore = 0;
        $totalDiscrepancy = 0;
        $projectsWithDiscrepancy = 0;

        foreach ($projectValidations as $validation) {
            if ($validation['validation_passed']) {
                $summary['projects_passed']++;
            } else {
                $summary['projects_failed']++;
            }

            $totalCompletenessScore += $validation['sync_completeness_score'];
            $summary['total_errors'] += count($validation['validation_errors']);
            $summary['total_warnings'] += count($validation['validation_warnings']);

            if ($validation['discrepancy_percentage'] > 0) {
                $totalDiscrepancy += $validation['discrepancy_percentage'];
                $projectsWithDiscrepancy++;
            }

            // Collect critical issues
            if ($validation['sync_completeness_score'] < 80) {
                $summary['critical_issues'][] = 
                    "Project {$validation['project_key']} has low completeness score: {$validation['sync_completeness_score']}%";
            }

            if ($validation['discrepancy_percentage'] > 10) {
                $summary['critical_issues'][] = 
                    "Project {$validation['project_key']} has high discrepancy: {$validation['discrepancy_percentage']}%";
            }
        }

        $summary['average_completeness_score'] = $totalCompletenessScore / count($projectValidations);
        
        if ($projectsWithDiscrepancy > 0) {
            $summary['overall_discrepancy_percentage'] = $totalDiscrepancy / $projectsWithDiscrepancy;
        }

        // Generate recommendations
        if ($summary['projects_failed'] > 0) {
            $summary['recommendations'][] = "Review failed project syncs and re-run worklog sync if necessary";
        }

        if ($summary['overall_discrepancy_percentage'] > 5) {
            $summary['recommendations'][] = "High discrepancy detected - consider running full sync for affected projects";
        }

        if ($summary['average_completeness_score'] < 90) {
            $summary['recommendations'][] = "Overall sync quality below optimal - review sync configuration and JIRA connectivity";
        }

        return $summary;
    }

    /**
     * Store validation results in sync status.
     */
    public function storeValidationResults(string $projectKey, array $validationResults): void
    {
        try {
            $syncStatus = JiraWorklogSyncStatus::where('project_key', $projectKey)->first();
            
            if ($syncStatus) {
                $metadata = $syncStatus->sync_metadata ?? [];
                $metadata['last_validation'] = [
                    'timestamp' => now()->toISOString(),
                    'validation_passed' => $validationResults['validation_passed'],
                    'completeness_score' => $validationResults['sync_completeness_score'],
                    'discrepancy_percentage' => $validationResults['discrepancy_percentage'],
                    'error_count' => count($validationResults['validation_errors']),
                    'warning_count' => count($validationResults['validation_warnings']),
                ];

                $syncStatus->update(['sync_metadata' => $metadata]);
            }

        } catch (\Exception $e) {
            Log::error("Failed to store validation results for {$projectKey}: " . $e->getMessage());
        }
    }
}