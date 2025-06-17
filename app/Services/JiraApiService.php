<?php

namespace App\Services;

use App\Models\JiraSetting;
use Exception;
use Illuminate\Support\Facades\Log;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use JiraRestApi\Project\ProjectService;

class JiraApiService
{
    protected $configuration;

    protected $jiraHost;

    // Rate limiting properties
    protected int $requestCount = 0;
    protected float $lastRequestTime = 0;
    protected int $rateLimitDelay = 0;
    protected array $rateLimitConfig = [
        'requests_per_minute' => 300, // Conservative limit (JIRA Cloud allows 1000/min)
        'requests_per_hour' => 10000, // Conservative hourly limit
        'batch_delay_ms' => 100, // Delay between batch requests
        'adaptive_delay' => true, // Enable adaptive delay based on response times
    ];

    /**
     * @throws Exception
     */
    public function __construct()
    {
        // Configuration will be loaded when settings are available
    }

    /**
     * Initialize the JIRA configuration with current settings.
     *
     * @throws Exception
     */
    protected function initializeConfiguration(): bool
    {
        $settings = JiraSetting::first();
        if (! $settings || ! $settings->jira_host || ! $settings->api_token) {
            Log::error('JIRA settings not configured.');
            throw new Exception('JIRA settings not configured. Please configure them in the settings page.');
        }

        $this->jiraHost = $settings->jira_host;
        // The lesstif/php-jira-rest-client expects the full host URL including https://
        $hostUrl = str_starts_with($this->jiraHost, 'http') ? $this->jiraHost : 'https://'.$this->jiraHost;

        try {
            // Get the user's email from settings (you may need to add this field)
            $email = $settings->jira_email ?? 'admin@example.com'; // You should store the email in settings

            $this->configuration = new ArrayConfiguration([
                'jiraHost' => $hostUrl,
                'jiraUser' => $email,
                'jiraPassword' => $settings->api_token,
                'jiraLogEnabled' => false,
                'jiraLogFile' => storage_path('logs/jira.log'),
                'jiraLogLevel' => 'WARNING',
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to initialize JIRA configuration: '.$e->getMessage());
            throw new Exception('Failed to configure JIRA connection: '.$e->getMessage());
        }
    }

    /**
     * Get all accessible projects from JIRA.
     */
    public function getAllProjects(): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        try {
            $projectService = new ProjectService($this->configuration);
            $projects = $projectService->getAllProjects();

            // Convert ArrayObject to array if needed
            if ($projects instanceof \ArrayObject) {
                $projects = $projects->getArrayCopy();
            } elseif (! is_array($projects)) {
                $projects = [];
            }

            Log::info('JiraApiService: Fetched all projects', ['count' => count($projects)]);

            return $projects;
        } catch (JiraException $e) {
            Log::error('JiraApiService: Failed to fetch all projects: '.$e->getMessage());
            throw new Exception('Failed to fetch projects from JIRA: '.$e->getMessage());
        }
    }

    /**
     * Get a list of projects from JIRA.
     * Potentially to verify project keys or fetch all accessible projects.
     */
    public function getProjects(array $projectKeys = []): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        $projects = [];

        try {
            $projectService = new ProjectService($this->configuration);

            if (empty($projectKeys)) {
                // Fetch all projects accessible by the API token
                $projectObjects = $projectService->getAllProjects();

                // Convert ArrayObject to array if needed
                if ($projectObjects instanceof \ArrayObject) {
                    $projectObjects = $projectObjects->getArrayCopy();
                } elseif (! is_array($projectObjects)) {
                    $projectObjects = [];
                }

                // Convert Project objects to arrays
                foreach ($projectObjects as $projectObj) {
                    $projects[] = $this->convertProjectToArray($projectObj);
                }
            } else {
                foreach ($projectKeys as $key) {
                    try {
                        $projectObj = $projectService->get($key);
                        if ($projectObj) {
                            $projects[] = $this->convertProjectToArray($projectObj);
                        }
                    } catch (JiraException $e) {
                        Log::error("JiraApiService: Failed to fetch project {$key}: ".$e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            Log::error('JiraApiService: Failed to fetch projects: '.$e->getMessage());
            throw new Exception('Failed to fetch projects: '.$e->getMessage());
        }

        Log::info('JiraApiService: getProjects executed.', ['count' => count($projects)]);

        return $projects;
    }

    /**
     * Convert a JIRA Project object to an array format expected by the import service.
     */
    private function convertProjectToArray($projectObj): array
    {
        // Handle both object and array cases
        if (is_array($projectObj)) {
            return $projectObj;
        }

        // Convert object properties to array
        return [
            'id' => $projectObj->id ?? $projectObj->getId() ?? null,
            'key' => $projectObj->key ?? $projectObj->getKey() ?? null,
            'name' => $projectObj->name ?? $projectObj->getName() ?? null,
            'description' => $projectObj->description ?? $projectObj->getDescription() ?? null,
            'lead' => $projectObj->lead ?? $projectObj->getLead() ?? null,
            'projectTypeKey' => $projectObj->projectTypeKey ?? $projectObj->getProjectTypeKey() ?? null,
            'self' => $projectObj->self ?? $projectObj->getSelf() ?? null,
        ];
    }

    /**
     * Convert a JIRA Issue object to an array format expected by the import service.
     */
    private function convertIssueToArray($issueObj): array
    {
        // Handle both object and array cases
        if (is_array($issueObj)) {
            return $issueObj;
        }

        // Convert Issue object to array structure
        $fields = [];
        if (isset($issueObj->fields)) {
            $fieldsObj = $issueObj->fields;
            $fields = [
                'summary' => $fieldsObj->summary ?? null,
                'status' => isset($fieldsObj->status) ? [
                    'id' => $fieldsObj->status->id ?? null,
                    'name' => $fieldsObj->status->name ?? null,
                ] : null,
                'assignee' => isset($fieldsObj->assignee) ? [
                    'accountId' => $fieldsObj->assignee->accountId ?? null,
                    'emailAddress' => $fieldsObj->assignee->emailAddress ?? null,
                    'displayName' => $fieldsObj->assignee->displayName ?? null,
                ] : null,
                'project' => isset($fieldsObj->project) ? [
                    'id' => $fieldsObj->project->id ?? null,
                    'key' => $fieldsObj->project->key ?? null,
                    'name' => $fieldsObj->project->name ?? null,
                ] : null,
                'issuetype' => isset($fieldsObj->issuetype) ? [
                    'id' => $fieldsObj->issuetype->id ?? null,
                    'name' => $fieldsObj->issuetype->name ?? null,
                ] : null,
                'created' => $fieldsObj->created ?? null,
                'updated' => $fieldsObj->updated ?? null,
                'timetracking' => isset($fieldsObj->timetracking) ? [
                    'originalEstimate' => $fieldsObj->timetracking->originalEstimate ?? null,
                    'remainingEstimate' => $fieldsObj->timetracking->remainingEstimate ?? null,
                    'timeSpent' => $fieldsObj->timetracking->timeSpent ?? null,
                ] : null,
            ];
        }

        return [
            'id' => $issueObj->id ?? null,
            'key' => $issueObj->key ?? null,
            'self' => $issueObj->self ?? null,
            'fields' => $fields,
        ];
    }

    /**
     * Convert a JIRA Worklog object to an array format expected by the import service.
     */
    private function convertWorklogToArray($worklogObj): array
    {
        // Handle both object and array cases
        if (is_array($worklogObj)) {
            return $worklogObj;
        }

        // Convert Worklog object to array structure
        $author = null;
        if (isset($worklogObj->author)) {
            $author = [
                'accountId' => $worklogObj->author->accountId ?? null,
                'emailAddress' => $worklogObj->author->emailAddress ?? null,
                'displayName' => $worklogObj->author->displayName ?? null,
            ];
        }

        return [
            'id' => $worklogObj->id ?? null,
            'comment' => $worklogObj->comment ?? null,
            'started' => $worklogObj->started ?? null,
            'timeSpent' => $worklogObj->timeSpent ?? null,
            'timeSpentSeconds' => $worklogObj->timeSpentSeconds ?? null,
            'created' => $worklogObj->created ?? null,
            'updated' => $worklogObj->updated ?? null,
            'author' => $author,
            'self' => $worklogObj->self ?? null,
        ];
    }

    /**
     * Get issues for a specific JIRA project key with pagination.
     * Optimized batch sizes and rate limiting based on JIRA API best practices.
     */
    public function getIssuesForProject(string $projectKey, array $options = []): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        $allIssues = [];
        $startAt = 0;
        
        // Optimized batch sizes based on JIRA API recommendations
        $maxResults = $options['maxResults'] ?? 100; // Increased from 50 to 100 for better performance
        $fields = $options['fields'] ?? ['key', 'summary', 'status', 'assignee', 'project', 'issuetype', 'created', 'updated', 'timetracking', 'worklog'];
        $expand = $options['expand'] ?? [];

        // Optimized JQL with better sorting for consistent pagination
        $jql = "project = '{$projectKey}' ORDER BY key ASC"; // Changed to key ASC for better pagination consistency

        Log::info("JiraApiService: Fetching issues for project {$projectKey} (optimized)", [
            'jql' => $jql,
            'maxResults' => $maxResults,
            'fields' => $fields,
            'expand' => $expand,
        ]);

        try {
            $issueService = new IssueService($this->configuration);
            $batchCount = 0;

            do {
                $batchCount++;
                Log::debug("JiraApiService: Fetching batch {$batchCount} for project {$projectKey}", [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults
                ]);

                $result = $issueService->search($jql, $startAt, $maxResults, $fields, $expand);

                if ($result && isset($result->issues)) {
                    $issues = $result->issues;

                    if (! empty($issues)) {
                        $allIssues = array_merge($allIssues, $issues);
                        Log::debug("JiraApiService: Batch {$batchCount} fetched " . count($issues) . " issues");
                    }

                    $total = $result->total ?? 0;
                    $startAt += count($issues);

                    // Add rate limiting delay between requests to avoid API limits
                    if (count($issues) === $maxResults && $startAt < $total) {
                        usleep(200000); // 200ms delay between requests to respect rate limits
                    }

                    if (count($issues) < $maxResults || $startAt >= $total) {
                        break;
                    }
                } else {
                    Log::warning("JiraApiService: No issues found for project {$projectKey} in batch {$batchCount}");
                    break;
                }

                // Configurable safety limit to prevent infinite loops
                $maxBatches = config('jira.max_batches', 10000); // FIXED: Configurable limit
                if ($batchCount > $maxBatches) {
                    Log::warning("JiraApiService: Reached configurable batch limit ({$maxBatches}) for project {$projectKey}");
                    break;
                }

            } while (true);

        } catch (JiraException $e) {
            Log::error("JiraApiService: Error fetching issues for project {$projectKey}: ".$e->getMessage());
            throw new Exception("Failed to fetch issues for project {$projectKey}: ".$e->getMessage());
        }

        Log::info("JiraApiService: Completed fetching issues for project {$projectKey}", [
            'total_issues' => count($allIssues),
            'batches_processed' => $batchCount
        ]);

        // Convert Issue objects to arrays for consistent processing
        $convertedIssues = [];
        foreach ($allIssues as $issueObj) {
            $convertedIssues[] = $this->convertIssueToArray($issueObj);
        }

        return $convertedIssues;
    }

    /**
     * Get worklogs for a specific JIRA issue key.
     */
    public function getWorklogsForIssue(string $issueKey): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        Log::info("JiraApiService: Fetching worklogs for issue {$issueKey}");

        try {
            $issueService = new IssueService($this->configuration);
            $worklogs = $issueService->getWorklog($issueKey)->getWorklogs();

            Log::info('JiraApiService: Fetched '.count($worklogs)." worklogs for issue {$issueKey}");

            // Convert Worklog objects to arrays for consistent processing
            $convertedWorklogs = [];
            foreach ($worklogs as $worklogObj) {
                $convertedWorklogs[] = $this->convertWorklogToArray($worklogObj);
            }

            return $convertedWorklogs;
        } catch (JiraException $e) {
            Log::error("JiraApiService: Error fetching worklogs for issue {$issueKey}: ".$e->getMessage());
            throw new Exception("Failed to fetch worklogs for issue {$issueKey}: ".$e->getMessage());
        }
    }

    /**
     * Test the JIRA connection with the current settings.
     */
    public function testConnection(): array
    {
        try {
            if (! $this->configuration) {
                $this->initializeConfiguration();
            }

            // Try to fetch the current user's info as a connection test
            $projectService = new ProjectService($this->configuration);
            $projects = $projectService->getAllProjects();

            return [
                'success' => true,
                'message' => 'Successfully connected to JIRA. Found '.count($projects).' accessible projects.',
                'data' => ['project_count' => count($projects)],
            ];
        } catch (Exception $e) {
            Log::error('JIRA connection test failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'JIRA connection test failed: '.$e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Apply intelligent rate limiting before making API requests.
     */
    protected function applyRateLimit(): void
    {
        $currentTime = microtime(true);
        
        // Update request count
        $this->requestCount++;
        
        // Calculate time since last request
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        // Apply base delay from configuration
        $baseDelay = $this->rateLimitConfig['batch_delay_ms'] * 1000; // Convert to microseconds
        
        // Apply adaptive delay if enabled
        if ($this->rateLimitConfig['adaptive_delay']) {
            // If requests are coming too fast, increase delay
            if ($timeSinceLastRequest < 0.1) { // Less than 100ms
                $baseDelay *= 2;
            }
            
            // Every 10 requests, add extra delay to be conservative
            if ($this->requestCount % 10 === 0) {
                $baseDelay += 500000; // Add 500ms every 10 requests
            }
        }
        
        // Apply the delay
        if ($baseDelay > 0) {
            usleep($baseDelay);
        }
        
        // Update last request time
        $this->lastRequestTime = microtime(true);
        
        Log::debug('Applied rate limit', [
            'request_count' => $this->requestCount,
            'delay_ms' => $baseDelay / 1000,
            'time_since_last' => $timeSinceLastRequest,
        ]);
    }

    /**
     * Check if we're approaching rate limits and apply exponential backoff.
     */
    protected function checkRateLimitThresholds(): void
    {
        $requestsPerMinute = $this->rateLimitConfig['requests_per_minute'];
        
        // If we're approaching the per-minute limit, apply exponential backoff
        if ($this->requestCount > 0 && $this->requestCount % ($requestsPerMinute / 4) === 0) {
            $backoffDelay = min(5000000, 250000 * pow(2, floor($this->requestCount / ($requestsPerMinute / 4))));
            
            Log::info('Applying exponential backoff for rate limiting', [
                'request_count' => $this->requestCount,
                'backoff_delay_ms' => $backoffDelay / 1000,
            ]);
            
            usleep($backoffDelay);
        }
    }

    /**
     * Enhanced method to get issues with intelligent batching and rate limiting.
     */
    public function getIssuesForProjectEnhanced(string $projectKey, array $options = []): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        $allIssues = [];
        $startAt = 0;
        
        // Enhanced batch configuration
        $maxResults = $options['maxResults'] ?? 50; // Start with smaller batches
        $fields = $options['fields'] ?? ['key', 'summary', 'status', 'assignee', 'project', 'issuetype', 'created', 'updated', 'timetracking'];
        $expand = $options['expand'] ?? [];
        $jql = $options['jql'] ?? "project = '{$projectKey}' ORDER BY updated ASC";

        Log::info("Enhanced JIRA API: Fetching issues for project {$projectKey}", [
            'jql' => $jql,
            'maxResults' => $maxResults,
            'fields' => $fields,
        ]);

        try {
            $issueService = new IssueService($this->configuration);
            $batchCount = 0;
            $totalRequestTime = 0;

            do {
                $batchCount++;
                $batchStartTime = microtime(true);
                
                // Apply rate limiting before each request
                $this->applyRateLimit();
                $this->checkRateLimitThresholds();
                
                Log::debug("Enhanced API: Fetching batch {$batchCount} for project {$projectKey}", [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'total_requests' => $this->requestCount,
                ]);

                $result = $issueService->search($jql, $startAt, $maxResults, $fields, $expand);
                $batchTime = microtime(true) - $batchStartTime;
                $totalRequestTime += $batchTime;

                if ($result && isset($result->issues)) {
                    $issues = $result->issues;

                    if (! empty($issues)) {
                        $allIssues = array_merge($allIssues, $issues);
                        Log::debug("Enhanced API: Batch {$batchCount} fetched " . count($issues) . " issues in " . round($batchTime * 1000, 2) . "ms");
                    }

                    $total = $result->total ?? 0;
                    $startAt += count($issues);

                    // Adaptive batch size adjustment
                    if ($this->rateLimitConfig['adaptive_delay']) {
                        if ($batchTime > 2.0) { // If request took > 2 seconds, reduce batch size
                            $maxResults = max(10, intval($maxResults * 0.8));
                            Log::info("Reducing batch size due to slow response", ['new_batch_size' => $maxResults]);
                        } elseif ($batchTime < 0.5 && $maxResults < 100) { // If fast, increase batch size
                            $maxResults = min(100, intval($maxResults * 1.2));
                            Log::debug("Increasing batch size due to fast response", ['new_batch_size' => $maxResults]);
                        }
                    }

                    if (count($issues) < $maxResults || $startAt >= $total) {
                        break;
                    }
                } else {
                    Log::warning("Enhanced API: No issues found for project {$projectKey} in batch {$batchCount}");
                    break;
                }

                // Safety limit
                if ($batchCount > 500) {
                    Log::warning("Enhanced API: Reached batch limit (500) for project {$projectKey}");
                    break;
                }

            } while (true);

        } catch (JiraException $e) {
            Log::error("Enhanced API: Error fetching issues for project {$projectKey}: " . $e->getMessage());
            
            // Check if it's a rate limit error and apply longer backoff
            if (str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), '429')) {
                Log::warning("Rate limit detected, applying extended backoff");
                sleep(60); // Wait 1 minute for rate limit errors
            }
            
            throw new Exception("Failed to fetch issues for project {$projectKey}: " . $e->getMessage());
        }

        Log::info("Enhanced API: Completed fetching issues for project {$projectKey}", [
            'total_issues' => count($allIssues),
            'batches_processed' => $batchCount,
            'total_time_seconds' => round($totalRequestTime, 2),
            'avg_batch_time_ms' => round(($totalRequestTime / max(1, $batchCount)) * 1000, 2),
            'total_api_requests' => $this->requestCount,
        ]);

        // Convert Issue objects to arrays
        $convertedIssues = [];
        foreach ($allIssues as $issueObj) {
            $convertedIssues[] = $this->convertIssueToArray($issueObj);
        }

        return $convertedIssues;
    }

    /**
     * Enhanced worklog fetching with rate limiting.
     */
    public function getWorklogsForIssueEnhanced(string $issueKey): array
    {
        if (! $this->configuration) {
            $this->initializeConfiguration();
        }

        // Apply rate limiting
        $this->applyRateLimit();

        Log::debug("Enhanced API: Fetching worklogs for issue {$issueKey}");

        try {
            $issueService = new IssueService($this->configuration);
            $worklogs = $issueService->getWorklog($issueKey)->getWorklogs();

            Log::debug('Enhanced API: Fetched ' . count($worklogs) . " worklogs for issue {$issueKey}");

            // Convert Worklog objects to arrays
            $convertedWorklogs = [];
            foreach ($worklogs as $worklogObj) {
                $convertedWorklogs[] = $this->convertWorklogToArray($worklogObj);
            }

            return $convertedWorklogs;
        } catch (JiraException $e) {
            Log::error("Enhanced API: Error fetching worklogs for issue {$issueKey}: " . $e->getMessage());
            
            // Check for rate limit
            if (str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), '429')) {
                Log::warning("Rate limit detected on worklog request, applying backoff");
                sleep(30); // Shorter wait for individual worklog requests
            }
            
            throw new Exception("Failed to fetch worklogs for issue {$issueKey}: " . $e->getMessage());
        }
    }

    /**
     * Batch process multiple issues for worklog fetching.
     */
    public function getWorklogsForIssuesBatch(array $issueKeys, callable $progressCallback = null): array
    {
        $allWorklogs = [];
        $totalIssues = count($issueKeys);
        $processed = 0;

        Log::info("Starting batch worklog fetch for {$totalIssues} issues");

        foreach ($issueKeys as $issueKey) {
            try {
                $worklogs = $this->getWorklogsForIssueEnhanced($issueKey);
                $allWorklogs[$issueKey] = $worklogs;
                $processed++;

                // Call progress callback if provided
                if ($progressCallback) {
                    $progressCallback($processed, $totalIssues, $issueKey);
                }

                // Add extra delay every 20 issues to be conservative
                if ($processed % 20 === 0) {
                    Log::debug("Batch processing: Adding conservative delay after {$processed} issues");
                    sleep(2);
                }

            } catch (Exception $e) {
                Log::error("Failed to fetch worklogs for issue {$issueKey}: " . $e->getMessage());
                $allWorklogs[$issueKey] = [];
                $processed++;
            }
        }

        Log::info("Completed batch worklog fetch", [
            'total_issues' => $totalIssues,
            'processed_issues' => $processed,
            'total_api_requests' => $this->requestCount,
        ]);

        return $allWorklogs;
    }

    /**
     * Get rate limiting statistics.
     */
    public function getRateLimitStats(): array
    {
        return [
            'total_requests' => $this->requestCount,
            'last_request_time' => $this->lastRequestTime,
            'current_delay' => $this->rateLimitDelay,
            'config' => $this->rateLimitConfig,
            'requests_per_minute_limit' => $this->rateLimitConfig['requests_per_minute'],
            'estimated_requests_this_minute' => min($this->requestCount, $this->rateLimitConfig['requests_per_minute']),
        ];
    }

    /**
     * Reset rate limiting counters.
     */
    public function resetRateLimitCounters(): void
    {
        $this->requestCount = 0;
        $this->lastRequestTime = 0;
        $this->rateLimitDelay = 0;
        
        Log::info('Rate limit counters reset');
    }
}
