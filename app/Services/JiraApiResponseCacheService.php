<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class JiraApiResponseCacheService
{
    // Cache TTL constants aligned with PRD requirements
    private const PROJECT_CACHE_TTL = 3600; // 1 hour for project data
    private const ISSUE_CACHE_TTL = 1800; // 30 minutes for issue data
    private const WORKLOG_CACHE_TTL = 900; // 15 minutes for worklog data
    private const SEARCH_CACHE_TTL = 600; // 10 minutes for search results
    private const METADATA_CACHE_TTL = 7200; // 2 hours for metadata (users, statuses, etc.)
    
    // Cache store
    private string $cacheStore = 'redis';
    
    // Cache key prefix
    private string $keyPrefix = 'jira_api_response';

    /**
     * Cache JIRA API response with intelligent TTL based on data type.
     */
    public function cacheApiResponse(string $endpoint, array $params, array $responseData, ?int $customTtl = null): void
    {
        $cacheKey = $this->generateCacheKey($endpoint, $params);
        $ttl = $customTtl ?? $this->determineTtl($endpoint);
        
        $cacheData = [
            'data' => $responseData,
            'cached_at' => now()->toISOString(),
            'endpoint' => $endpoint,
            'params' => $params,
            'ttl' => $ttl,
            'data_type' => $this->determineDataType($endpoint)
        ];
        
        Cache::store($this->cacheStore)->put($cacheKey, $cacheData, $ttl);
        
        Log::debug('JIRA API response cached', [
            'endpoint' => $endpoint,
            'cache_key' => $cacheKey,
            'ttl_seconds' => $ttl,
            'data_size' => strlen(json_encode($responseData)),
            'params_count' => count($params)
        ]);
    }

    /**
     * Retrieve cached JIRA API response.
     */
    public function getCachedApiResponse(string $endpoint, array $params): ?array
    {
        $cacheKey = $this->generateCacheKey($endpoint, $params);
        $cached = Cache::store($this->cacheStore)->get($cacheKey);
        
        if ($cached === null) {
            Log::debug('JIRA API cache miss', [
                'endpoint' => $endpoint,
                'cache_key' => $cacheKey,
                'params' => $params
            ]);
            return null;
        }
        
        // Validate cache freshness
        if ($this->isCacheStale($cached)) {
            $this->invalidateApiResponseCache($endpoint, $params);
            Log::debug('JIRA API cache stale, invalidated', [
                'endpoint' => $endpoint,
                'cache_key' => $cacheKey,
                'cached_at' => $cached['cached_at']
            ]);
            return null;
        }
        
        Log::debug('JIRA API cache hit', [
            'endpoint' => $endpoint,
            'cache_key' => $cacheKey,
            'cached_at' => $cached['cached_at'],
            'data_type' => $cached['data_type'] ?? 'unknown'
        ]);
        
        return $cached['data'];
    }

    /**
     * Invalidate cached API response.
     */
    public function invalidateApiResponseCache(string $endpoint, array $params): bool
    {
        $cacheKey = $this->generateCacheKey($endpoint, $params);
        $result = Cache::store($this->cacheStore)->forget($cacheKey);
        
        Log::debug('JIRA API cache invalidated', [
            'endpoint' => $endpoint,
            'cache_key' => $cacheKey,
            'success' => $result
        ]);
        
        return $result;
    }

    /**
     * Invalidate all cached responses for a specific project.
     */
    public function invalidateProjectApiCache(string $projectKey): int
    {
        $pattern = $this->keyPrefix . ':*:project:' . $projectKey . ':*';
        return $this->invalidateCacheByPattern($pattern);
    }

    /**
     * Invalidate all cached responses for a specific endpoint.
     */
    public function invalidateEndpointCache(string $endpoint): int
    {
        $endpointHash = $this->hashEndpoint($endpoint);
        $pattern = $this->keyPrefix . ':' . $endpointHash . ':*';
        return $this->invalidateCacheByPattern($pattern);
    }

    /**
     * Warm API response cache for frequently accessed endpoints.
     */
    public function warmApiCache(string $projectKey, array $endpoints = []): array
    {
        $defaultEndpoints = [
            'project' => "/rest/api/2/project/{$projectKey}",
            'issues' => "/rest/api/2/search",
            'worklogs' => "/rest/api/2/project/{$projectKey}/worklog/updated",
            'metadata' => "/rest/api/2/project/{$projectKey}/statuses"
        ];
        
        $endpointsToWarm = empty($endpoints) ? $defaultEndpoints : $endpoints;
        $warmedEndpoints = [];
        
        foreach ($endpointsToWarm as $type => $endpoint) {
            try {
                // This would be called in conjunction with actual API calls
                // For now, we'll just prepare the cache structure
                $warmedEndpoints[$type] = [
                    'endpoint' => $endpoint,
                    'cache_key' => $this->generateCacheKey($endpoint, ['project' => $projectKey]),
                    'ttl' => $this->determineTtl($endpoint),
                    'status' => 'prepared'
                ];
            } catch (\Exception $e) {
                Log::warning('Failed to warm API cache', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage()
                ]);
                $warmedEndpoints[$type] = [
                    'endpoint' => $endpoint,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        Log::info('API cache warming completed', [
            'project_key' => $projectKey,
            'endpoints_warmed' => count($warmedEndpoints),
            'results' => $warmedEndpoints
        ]);
        
        return $warmedEndpoints;
    }

    /**
     * Get cache statistics for monitoring.
     */
    public function getCacheStatistics(): array
    {
        $pattern = $this->keyPrefix . ':*';
        $keys = $this->getCacheKeysByPattern($pattern);
        
        $stats = [
            'total_cached_responses' => 0,
            'cache_size_mb' => 0,
            'data_types' => [],
            'endpoints' => [],
            'age_distribution' => [
                'fresh' => 0,    // < 15 minutes
                'moderate' => 0, // 15-60 minutes
                'old' => 0       // > 60 minutes
            ]
        ];
        
        foreach ($keys as $key) {
            $cached = Cache::store($this->cacheStore)->get($key);
            if ($cached === null) continue;
            
            $stats['total_cached_responses']++;
            
            // Calculate size
            $size = strlen(json_encode($cached)) / (1024 * 1024); // MB
            $stats['cache_size_mb'] += $size;
            
            // Track data types
            $dataType = $cached['data_type'] ?? 'unknown';
            $stats['data_types'][$dataType] = ($stats['data_types'][$dataType] ?? 0) + 1;
            
            // Track endpoints
            $endpoint = $cached['endpoint'] ?? 'unknown';
            $stats['endpoints'][$endpoint] = ($stats['endpoints'][$endpoint] ?? 0) + 1;
            
            // Age distribution
            $cachedAt = Carbon::parse($cached['cached_at']);
            $ageMinutes = $cachedAt->diffInMinutes(now());
            
            if ($ageMinutes < 15) {
                $stats['age_distribution']['fresh']++;
            } elseif ($ageMinutes <= 60) {
                $stats['age_distribution']['moderate']++;
            } else {
                $stats['age_distribution']['old']++;
            }
        }
        
        $stats['cache_size_mb'] = round($stats['cache_size_mb'], 2);
        
        return $stats;
    }

    /**
     * Clean up expired API response cache entries.
     */
    public function cleanupExpiredCache(): int
    {
        $pattern = $this->keyPrefix . ':*';
        $keys = $this->getCacheKeysByPattern($pattern);
        $cleanedCount = 0;
        
        foreach ($keys as $key) {
            $cached = Cache::store($this->cacheStore)->get($key);
            if ($cached === null) continue;
            
            if ($this->isCacheStale($cached)) {
                Cache::store($this->cacheStore)->forget($key);
                $cleanedCount++;
            }
        }
        
        Log::info('API response cache cleanup completed', [
            'cleaned_entries' => $cleanedCount,
            'total_checked' => count($keys)
        ]);
        
        return $cleanedCount;
    }

    /**
     * Generate cache key for API response.
     */
    private function generateCacheKey(string $endpoint, array $params): string
    {
        $endpointHash = $this->hashEndpoint($endpoint);
        $paramsHash = $this->hashParams($params);
        
        // Include project key in cache key for better organization
        $projectKey = $params['project'] ?? $params['projectKey'] ?? 'global';
        
        return "{$this->keyPrefix}:{$endpointHash}:project:{$projectKey}:{$paramsHash}";
    }

    /**
     * Determine appropriate TTL based on endpoint type.
     */
    private function determineTtl(string $endpoint): int
    {
        // Analyze endpoint to determine data type and appropriate TTL
        if (str_contains($endpoint, '/project/')) {
            if (str_contains($endpoint, '/worklog')) {
                return self::WORKLOG_CACHE_TTL;
            }
            return self::PROJECT_CACHE_TTL;
        }
        
        if (str_contains($endpoint, '/issue/')) {
            return self::ISSUE_CACHE_TTL;
        }
        
        if (str_contains($endpoint, '/search')) {
            return self::SEARCH_CACHE_TTL;
        }
        
        if (str_contains($endpoint, '/user') || str_contains($endpoint, '/status')) {
            return self::METADATA_CACHE_TTL;
        }
        
        // Default TTL for unknown endpoints
        return self::ISSUE_CACHE_TTL;
    }

    /**
     * Determine data type based on endpoint.
     */
    private function determineDataType(string $endpoint): string
    {
        if (str_contains($endpoint, '/project/')) {
            if (str_contains($endpoint, '/worklog')) {
                return 'worklog';
            }
            return 'project';
        }
        
        if (str_contains($endpoint, '/issue/')) {
            return 'issue';
        }
        
        if (str_contains($endpoint, '/search')) {
            return 'search';
        }
        
        if (str_contains($endpoint, '/user')) {
            return 'user';
        }
        
        if (str_contains($endpoint, '/status')) {
            return 'status';
        }
        
        return 'unknown';
    }

    /**
     * Check if cached data is stale based on PRD requirements.
     */
    private function isCacheStale(array $cached): bool
    {
        $cachedAt = Carbon::parse($cached['cached_at']);
        $ttl = $cached['ttl'] ?? self::ISSUE_CACHE_TTL;
        
        return $cachedAt->addSeconds($ttl)->isPast();
    }

    /**
     * Hash endpoint for consistent cache keys.
     */
    private function hashEndpoint(string $endpoint): string
    {
        return substr(md5($endpoint), 0, 8);
    }

    /**
     * Hash parameters for consistent cache keys.
     */
    private function hashParams(array $params): string
    {
        ksort($params);
        return substr(md5(json_encode($params)), 0, 8);
    }

    /**
     * Invalidate cache entries matching a pattern.
     */
    private function invalidateCacheByPattern(string $pattern): int
    {
        $keys = $this->getCacheKeysByPattern($pattern);
        $deletedCount = 0;
        
        foreach ($keys as $key) {
            if (Cache::store($this->cacheStore)->forget($key)) {
                $deletedCount++;
            }
        }
        
        return $deletedCount;
    }

    /**
     * Get cache keys matching a pattern.
     * Note: This is a simplified implementation. In production, you might want to use
     * Redis SCAN command for better performance.
     */
    private function getCacheKeysByPattern(string $pattern): array
    {
        // This is a simplified implementation
        // In production, use Redis SCAN for better performance
        try {
            $redis = Cache::store($this->cacheStore)->getRedis();
            return $redis->keys($pattern);
        } catch (\Exception $e) {
            Log::warning('Failed to get cache keys by pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}