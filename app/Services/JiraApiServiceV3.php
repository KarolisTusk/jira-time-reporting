<?php

namespace App\Services;

use App\Models\JiraSetting;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * JIRA API Service optimized for JIRA Cloud REST API v3
 * Following official documentation: https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/
 * Rate limiting: https://developer.atlassian.com/cloud/jira/platform/rate-limiting/
 */
class JiraApiServiceV3
{
    protected string $baseUrl;
    protected string $email;
    protected string $apiToken;
    
    // Rate limiting based on JIRA Cloud documentation
    protected array $rateLimits = [
        'requests_per_second' => 10,      // Conservative: 10 req/sec (JIRA allows more)
        'concurrent_requests' => 3,       // Max concurrent requests
        'batch_size_issues' => 50,        // Optimal batch size for issue search
        'batch_size_worklogs' => 20,      // Smaller batches for worklog requests
        'retry_attempts' => 3,            // Retry failed requests
        'backoff_base_ms' => 1000,        // Base backoff delay in milliseconds
    ];
    
    protected int $requestCount = 0;
    protected float $lastRequestTime = 0;

    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * Load JIRA configuration from database
     */
    protected function loadConfiguration(): void
    {
        $settings = JiraSetting::first();
        if (!$settings || !$settings->jira_host || !$settings->api_token || !$settings->jira_email) {
            throw new Exception('JIRA settings not properly configured. Please configure host, email, and API token.');
        }

        $this->baseUrl = rtrim($settings->jira_host, '/');
        if (!str_starts_with($this->baseUrl, 'http')) {
            $this->baseUrl = 'https://' . $this->baseUrl;
        }
        
        $this->email = $settings->jira_email;
        $this->apiToken = $settings->api_token;
        
        Log::debug('JIRA API v3 configured', [
            'base_url' => $this->baseUrl,
            'email' => $this->email,
        ]);
    }

    /**
     * Apply rate limiting before making requests
     */
    protected function applyRateLimit(): void
    {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        // Ensure minimum delay between requests (100ms = 10 req/sec)
        $minDelay = 1.0 / $this->rateLimits['requests_per_second'];
        
        if ($timeSinceLastRequest < $minDelay) {
            $sleepTime = ($minDelay - $timeSinceLastRequest) * 1000000; // Convert to microseconds
            usleep((int) $sleepTime);
        }
        
        $this->requestCount++;
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Make HTTP request to JIRA API with retry logic
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $this->applyRateLimit();
        
        $url = $this->baseUrl . '/rest/api/3/' . ltrim($endpoint, '/');
        
        for ($attempt = 1; $attempt <= $this->rateLimits['retry_attempts']; $attempt++) {
            try {
                Log::debug("JIRA API v3 request", [
                    'method' => $method,
                    'url' => $url,
                    'params' => $params,
                    'attempt' => $attempt,
                ]);

                if ($method === 'GET') {
                    $response = Http::withBasicAuth($this->email, $this->apiToken)
                        ->timeout(60)
                        ->retry(2, 1000)
                        ->get($url, $params);
                } else {
                    $response = Http::withBasicAuth($this->email, $this->apiToken)
                        ->timeout(60)
                        ->retry(2, 1000)
                        ->post($url, $params);
                }

                if ($response->successful()) {
                    $data = $response->json();
                    
                    Log::debug("JIRA API v3 response successful", [
                        'status' => $response->status(),
                        'response_size' => strlen($response->body()),
                        'attempt' => $attempt,
                    ]);
                    
                    return $data ?? [];
                }

                // Handle rate limiting (429 status)
                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After', 60); // Default to 60 seconds
                    Log::warning("Rate limit hit, retrying after {$retryAfter} seconds", [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                    ]);
                    
                    if ($attempt < $this->rateLimits['retry_attempts']) {
                        sleep((int) $retryAfter);
                        continue;
                    }
                }

                // Handle other HTTP errors
                Log::error("JIRA API v3 request failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < $this->rateLimits['retry_attempts']) {
                    $backoffDelay = $this->rateLimits['backoff_base_ms'] * pow(2, $attempt - 1);
                    Log::info("Retrying request after {$backoffDelay}ms", ['attempt' => $attempt]);
                    usleep($backoffDelay * 1000); // Convert to microseconds
                    continue;
                }

                throw new Exception("JIRA API request failed after {$this->rateLimits['retry_attempts']} attempts. Status: {$response->status()}, Body: {$response->body()}");

            } catch (Exception $e) {
                Log::error("JIRA API v3 request exception", [
                    'message' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $this->rateLimits['retry_attempts']) {
                    throw $e;
                }

                $backoffDelay = $this->rateLimits['backoff_base_ms'] * pow(2, $attempt - 1);
                usleep($backoffDelay * 1000);
            }
        }

        throw new Exception('Failed to make JIRA API request after maximum retry attempts');
    }

    /**
     * Test JIRA connection
     */
    public function testConnection(): array
    {
        try {
            $data = $this->makeRequest('project/search', ['maxResults' => 1]);
            
            return [
                'success' => true,
                'message' => 'Successfully connected to JIRA API v3',
                'data' => [
                    'total_projects' => $data['total'] ?? 0,
                    'api_version' => '3',
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'JIRA connection failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Get projects with optimized pagination
     */
    public function getProjects(array $projectKeys = []): array
    {
        try {
            if (!empty($projectKeys)) {
                // Get specific projects
                $projects = [];
                foreach ($projectKeys as $key) {
                    try {
                        $project = $this->makeRequest("project/{$key}");
                        $projects[] = $project;
                    } catch (Exception $e) {
                        Log::warning("Failed to fetch project {$key}: " . $e->getMessage());
                    }
                }
                return $projects;
            }

            // Get all accessible projects
            $allProjects = [];
            $startAt = 0;
            $maxResults = 50;

            do {
                $data = $this->makeRequest('project/search', [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                ]);

                $projects = $data['values'] ?? [];
                $allProjects = array_merge($allProjects, $projects);

                $startAt += count($projects);
                $total = $data['total'] ?? 0;

            } while (count($projects) === $maxResults && $startAt < $total);

            Log::info('Fetched projects from JIRA v3 API', ['count' => count($allProjects)]);
            return $allProjects;

        } catch (Exception $e) {
            Log::error('Failed to fetch projects: ' . $e->getMessage());
            throw new Exception('Failed to fetch projects: ' . $e->getMessage());
        }
    }

    /**
     * Get issues for project with optimized JQL and pagination
     */
    public function getIssuesForProject(string $projectKey, array $options = []): array
    {
        $maxResults = $options['maxResults'] ?? $this->rateLimits['batch_size_issues'];
        $fields = $options['fields'] ?? [
            'id', 'key', 'summary', 'status', 'assignee', 'project', 
            'issuetype', 'created', 'updated', 'timetracking', 'labels', 'parent'
        ];
        
        // Build optimized JQL query
        $jql = $options['jql'] ?? "project = '{$projectKey}'";
        
        // Add default ordering for consistent pagination
        if (!str_contains(strtolower($jql), 'order by')) {
            $jql .= ' ORDER BY created ASC'; // Use created instead of updated for better performance
        }

        Log::info("Fetching issues for project {$projectKey} with JIRA v3 API", [
            'jql' => $jql,
            'maxResults' => $maxResults,
            'fields' => $fields,
        ]);

        try {
            $allIssues = [];
            $startAt = 0;
            $batchCount = 0;

            do {
                $batchCount++;
                
                $params = [
                    'jql' => $jql,
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'fields' => implode(',', $fields),
                ];

                $data = $this->makeRequest('search', $params);

                $issues = $data['issues'] ?? [];
                if (!empty($issues)) {
                    $allIssues = array_merge($allIssues, $issues);
                    Log::debug("Batch {$batchCount}: fetched " . count($issues) . " issues");
                }

                $startAt += count($issues);
                $total = $data['total'] ?? 0;

                // Configurable safety check to prevent infinite loops
                $maxBatches = config('jira.max_batches_v3', 5000); // FIXED: Configurable limit
                if ($batchCount > $maxBatches) {
                    Log::warning("Reached configurable batch limit ({$maxBatches}) for project {$projectKey}");
                    break;
                }

            } while (count($issues) === $maxResults && $startAt < $total);

            Log::info("Completed fetching issues for project {$projectKey}", [
                'total_issues' => count($allIssues),
                'batches' => $batchCount,
                'api_requests' => $this->requestCount,
            ]);

            return $allIssues;

        } catch (Exception $e) {
            Log::error("Failed to fetch issues for project {$projectKey}: " . $e->getMessage());
            throw new Exception("Failed to fetch issues for project {$projectKey}: " . $e->getMessage());
        }
    }

    /**
     * Get worklogs for an issue
     */
    public function getWorklogsForIssue(string $issueKey): array
    {
        try {
            Log::debug("Fetching worklogs for issue {$issueKey}");
            
            $allWorklogs = [];
            $startAt = 0;
            $maxResults = $this->rateLimits['batch_size_worklogs'];

            do {
                $data = $this->makeRequest("issue/{$issueKey}/worklog", [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                ]);

                $worklogs = $data['worklogs'] ?? [];
                if (!empty($worklogs)) {
                    $allWorklogs = array_merge($allWorklogs, $worklogs);
                }

                $startAt += count($worklogs);
                $total = $data['total'] ?? 0;

            } while (count($worklogs) === $maxResults && $startAt < $total);

            Log::debug("Fetched " . count($allWorklogs) . " worklogs for issue {$issueKey}");
            return $allWorklogs;

        } catch (Exception $e) {
            Log::error("Failed to fetch worklogs for issue {$issueKey}: " . $e->getMessage());
            throw new Exception("Failed to fetch worklogs for issue {$issueKey}: " . $e->getMessage());
        }
    }

    /**
     * Get optimized issue data with incremental sync support
     */
    public function getIssuesIncremental(string $projectKey, ?\Carbon\Carbon $since = null, bool $onlyWithWorklogs = false): array
    {
        $jql = "project = '{$projectKey}'";
        
        // Add incremental filter
        if ($since) {
            $jqlDate = $since->format('Y-m-d H:i');
            $jql .= " AND updated >= '{$jqlDate}'";
        }
        
        // Filter for issues with worklogs only
        if ($onlyWithWorklogs) {
            $jql .= " AND worklogDate is not EMPTY";
        }
        
        // Optimize ordering for incremental syncs
        $jql .= " ORDER BY updated ASC";
        
        Log::info("Incremental sync JQL for {$projectKey}", [
            'jql' => $jql,
            'since' => $since?->toISOString(),
            'only_with_worklogs' => $onlyWithWorklogs,
        ]);

        return $this->getIssuesForProject($projectKey, [
            'jql' => $jql,
            'maxResults' => $this->rateLimits['batch_size_issues'],
        ]);
    }

    /**
     * Search for issues using JQL
     */
    public function searchIssues(string $jql, int $startAt = 0, int $maxResults = 50): array
    {
        Log::debug("Searching issues with JQL", [
            'jql' => $jql,
            'startAt' => $startAt,
            'maxResults' => $maxResults,
        ]);

        $response = $this->makeRequest('search', [
            'jql' => $jql,
            'startAt' => $startAt,
            'maxResults' => $maxResults,
            'fields' => 'summary,status,assignee,created,updated,worklog,labels,parent',
            'expand' => 'names',
        ]);

        return [
            'issues' => $response['issues'] ?? [],
            'total' => $response['total'] ?? 0,
            'startAt' => $response['startAt'] ?? $startAt,
            'maxResults' => $response['maxResults'] ?? $maxResults,
        ];
    }

    /**
     * Get rate limiting statistics
     */
    public function getRateLimitStats(): array
    {
        return [
            'total_requests' => $this->requestCount,
            'last_request_time' => $this->lastRequestTime,
            'rate_limits' => $this->rateLimits,
            'requests_per_second_limit' => $this->rateLimits['requests_per_second'],
        ];
    }

    /**
     * Reset request counters
     */
    public function resetCounters(): void
    {
        $this->requestCount = 0;
        $this->lastRequestTime = 0;
        Log::info('JIRA API v3 request counters reset');
    }
}