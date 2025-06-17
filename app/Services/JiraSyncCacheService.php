<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\JiraProject;
use App\Models\JiraWorklog;

class JiraSyncCacheService
{
    private const CACHE_PREFIX = 'jira_sync:';
    private const WORKLOG_CACHE_TTL = 3600; // 1 hour
    private const PROJECT_CACHE_TTL = 1800; // 30 minutes
    private const METRICS_CACHE_TTL = 600; // 10 minutes
    private const API_RESPONSE_CACHE_TTL = 900; // 15 minutes
    
    // Cache performance tracking
    private static array $cacheMetrics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'invalidations' => 0,
    ];

    /**
     * Cache worklog data for a project.
     */
    public function cacheProjectWorklogs(string $projectKey, array $worklogs, ?Carbon $syncDate = null): void
    {
        $key = $this->getWorklogCacheKey($projectKey, $syncDate);
        
        Cache::put($key, [
            'worklogs' => $worklogs,
            'cached_at' => now(),
            'count' => count($worklogs)
        ], self::WORKLOG_CACHE_TTL);

        self::$cacheMetrics['writes']++;

        Log::debug("Cached {count} worklogs for project {project}", [
            'count' => count($worklogs),
            'project' => $projectKey,
            'cache_key' => $key
        ]);
    }

    /**
     * Get cached worklog data for a project.
     */
    public function getCachedProjectWorklogs(string $projectKey, ?Carbon $syncDate = null): ?array
    {
        $key = $this->getWorklogCacheKey($projectKey, $syncDate);
        
        $cached = Cache::get($key);
        
        if ($cached) {
            self::$cacheMetrics['hits']++;
            Log::debug("Retrieved {count} cached worklogs for project {project}", [
                'count' => $cached['count'] ?? 0,
                'project' => $projectKey,
                'cached_at' => $cached['cached_at'] ?? null
            ]);
        } else {
            self::$cacheMetrics['misses']++;
        }

        return $cached;
    }

    /**
     * Cache JIRA API response data.
     */
    public function cacheApiResponse(string $endpoint, array $params, array $response): void
    {
        $key = $this->getApiResponseCacheKey($endpoint, $params);
        
        Cache::put($key, [
            'response' => $response,
            'cached_at' => now(),
            'endpoint' => $endpoint,
            'params' => $params
        ], self::API_RESPONSE_CACHE_TTL);

        self::$cacheMetrics['writes']++;

        Log::debug("Cached API response for {endpoint}", [
            'endpoint' => $endpoint,
            'cache_key' => $key,
            'response_size' => count($response)
        ]);
    }

    /**
     * Get cached API response.
     */
    public function getCachedApiResponse(string $endpoint, array $params): ?array
    {
        $key = $this->getApiResponseCacheKey($endpoint, $params);
        
        $cached = Cache::get($key);
        
        if ($cached) {
            self::$cacheMetrics['hits']++;
        } else {
            self::$cacheMetrics['misses']++;
        }
        
        return $cached;
    }

    /**
     * Cache sync metrics for monitoring.
     */
    public function cacheSyncMetrics(string $projectKey, array $metrics): void
    {
        $key = $this->getSyncMetricsCacheKey($projectKey);
        
        Cache::put($key, [
            'metrics' => $metrics,
            'cached_at' => now(),
            'project_key' => $projectKey
        ], self::METRICS_CACHE_TTL);
    }

    /**
     * Get cached sync metrics.
     */
    public function getCachedSyncMetrics(string $projectKey): ?array
    {
        $key = $this->getSyncMetricsCacheKey($projectKey);
        
        return Cache::get($key);
    }

    /**
     * Cache project summary data.
     */
    public function cacheProjectSummary(string $projectKey, array $summary): void
    {
        $key = $this->getProjectSummaryCacheKey($projectKey);
        
        Cache::put($key, [
            'summary' => $summary,
            'cached_at' => now(),
            'project_key' => $projectKey
        ], self::PROJECT_CACHE_TTL);
    }

    /**
     * Get cached project summary.
     */
    public function getCachedProjectSummary(string $projectKey): ?array
    {
        $key = $this->getProjectSummaryCacheKey($projectKey);
        
        return Cache::get($key);
    }

    /**
     * Invalidate cache for a specific project.
     */
    public function invalidateProjectCache(string $projectKey): void
    {
        $patterns = [
            $this->getWorklogCacheKey($projectKey) . '*',
            $this->getSyncMetricsCacheKey($projectKey),
            $this->getProjectSummaryCacheKey($projectKey)
        ];

        foreach ($patterns as $pattern) {
            $this->invalidateCachePattern($pattern);
        }

        self::$cacheMetrics['invalidations']++;

        Log::info("Invalidated cache for project {project}", [
            'project' => $projectKey
        ]);
    }

    /**
     * Invalidate all JIRA sync related cache.
     */
    public function invalidateAllSyncCache(): void
    {
        $this->invalidateCachePattern(self::CACHE_PREFIX . '*');
        
        self::$cacheMetrics['invalidations']++;
        
        Log::info("Invalidated all JIRA sync cache");
    }

    /**
     * Warm up cache for recently active projects.
     */
    public function warmUpCache(array $projectKeys = null): void
    {
        $projects = $projectKeys ?? JiraProject::pluck('key')->toArray();
        
        foreach ($projects as $projectKey) {
            $this->warmUpProjectCache($projectKey);
        }

        Log::info("Warmed up cache for {count} projects", [
            'count' => count($projects),
            'projects' => $projects
        ]);
    }

    /**
     * Warm up cache for a specific project.
     */
    private function warmUpProjectCache(string $projectKey): void
    {
        // Cache recent worklog summary
        $recentWorklogs = JiraWorklog::whereHas('issue.project', function($query) use ($projectKey) {
            $query->where('key', $projectKey);
        })
        ->where('updated_at', '>=', now()->subDays(7))
        ->with(['issue', 'user'])
        ->get()
        ->toArray();

        if (!empty($recentWorklogs)) {
            $this->cacheProjectWorklogs($projectKey, $recentWorklogs, now()->subDays(7));
        }

        // Cache project summary
        $summary = [
            'total_worklogs' => JiraWorklog::whereHas('issue.project', function($query) use ($projectKey) {
                $query->where('key', $projectKey);
            })->count(),
            'total_hours' => JiraWorklog::whereHas('issue.project', function($query) use ($projectKey) {
                $query->where('key', $projectKey);
            })->sum('time_spent_seconds') / 3600,
            'last_updated' => now()
        ];

        $this->cacheProjectSummary($projectKey, $summary);
    }

    /**
     * Get cache statistics for monitoring.
     */
    public function getCacheStats(): array
    {
        $keys = $this->getCacheKeys();
        
        $totalOperations = self::$cacheMetrics['hits'] + self::$cacheMetrics['misses'];
        $hitRate = $totalOperations > 0 ? (self::$cacheMetrics['hits'] / $totalOperations) * 100 : 0;
        
        return [
            'total_keys' => count($keys),
            'worklog_cache_keys' => count(array_filter($keys, fn($key) => str_contains($key, ':worklogs:'))),
            'api_cache_keys' => count(array_filter($keys, fn($key) => str_contains($key, ':api:'))),
            'metrics_cache_keys' => count(array_filter($keys, fn($key) => str_contains($key, ':metrics:'))),
            'summary_cache_keys' => count(array_filter($keys, fn($key) => str_contains($key, ':summary:'))),
            'cache_memory_usage' => $this->estimateCacheMemoryUsage($keys),
            'performance_metrics' => [
                'cache_hits' => self::$cacheMetrics['hits'],
                'cache_misses' => self::$cacheMetrics['misses'],
                'cache_writes' => self::$cacheMetrics['writes'],
                'cache_invalidations' => self::$cacheMetrics['invalidations'],
                'hit_rate_percentage' => round($hitRate, 2),
                'total_operations' => $totalOperations,
            ]
        ];
    }

    /**
     * Get current performance metrics.
     */
    public function getPerformanceMetrics(): array
    {
        $totalOperations = self::$cacheMetrics['hits'] + self::$cacheMetrics['misses'];
        $hitRate = $totalOperations > 0 ? (self::$cacheMetrics['hits'] / $totalOperations) * 100 : 0;
        
        return [
            'cache_hits' => self::$cacheMetrics['hits'],
            'cache_misses' => self::$cacheMetrics['misses'],
            'cache_writes' => self::$cacheMetrics['writes'],
            'cache_invalidations' => self::$cacheMetrics['invalidations'],
            'hit_rate_percentage' => round($hitRate, 2),
            'total_operations' => $totalOperations,
        ];
    }

    /**
     * Reset performance metrics.
     */
    public function resetPerformanceMetrics(): void
    {
        self::$cacheMetrics = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'invalidations' => 0,
        ];
        
        Log::info('Cache performance metrics reset');
    }

    /**
     * Generate worklog cache key.
     */
    private function getWorklogCacheKey(string $projectKey, ?Carbon $syncDate = null): string
    {
        $dateStr = $syncDate ? $syncDate->format('Y-m-d') : 'all';
        return self::CACHE_PREFIX . "worklogs:{$projectKey}:{$dateStr}";
    }

    /**
     * Generate API response cache key.
     */
    private function getApiResponseCacheKey(string $endpoint, array $params): string
    {
        $paramHash = md5(json_encode($params));
        return self::CACHE_PREFIX . "api:" . str_replace('/', '_', $endpoint) . ":{$paramHash}";
    }

    /**
     * Generate sync metrics cache key.
     */
    private function getSyncMetricsCacheKey(string $projectKey): string
    {
        return self::CACHE_PREFIX . "metrics:{$projectKey}";
    }

    /**
     * Generate project summary cache key.
     */
    private function getProjectSummaryCacheKey(string $projectKey): string
    {
        return self::CACHE_PREFIX . "summary:{$projectKey}";
    }

    /**
     * Invalidate cache keys matching a pattern.
     */
    private function invalidateCachePattern(string $pattern): void
    {
        $keys = $this->getCacheKeys($pattern);
        
        if (!empty($keys)) {
            // Use Redis if available, otherwise fall back to individual deletes
            try {
                if (config('cache.default') === 'redis') {
                    Cache::store('redis')->deleteMultiple($keys);
                } else {
                    // Fallback for non-Redis cache stores
                    foreach ($keys as $key) {
                        Cache::forget($key);
                    }
                }
            } catch (Exception $e) {
                // Fallback to individual deletes if Redis operations fail
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
            }
        }
    }

    /**
     * Get all cache keys matching a pattern.
     */
    private function getCacheKeys(string $pattern = null): array
    {
        try {
            if (config('cache.default') === 'redis') {
                $redis = Cache::store('redis')->getRedis();
                $pattern = $pattern ?? (self::CACHE_PREFIX . '*');
                
                return $redis->keys($pattern) ?? [];
            }
        } catch (Exception $e) {
            // Redis not available, return empty array
        }
        
        // For non-Redis stores, we can't efficiently get keys by pattern
        // Return empty array to avoid errors
        return [];
    }

    /**
     * Estimate cache memory usage.
     */
    private function estimateCacheMemoryUsage(array $keys): string
    {
        $totalSize = 0;
        
        try {
            if (config('cache.default') === 'redis') {
                $redis = Cache::store('redis')->getRedis();
                
                foreach (array_slice($keys, 0, 100) as $key) { // Sample first 100 keys
                    $totalSize += $redis->memory('usage', $key) ?? 0;
                }
            } else {
                // For non-Redis stores, estimate based on key count
                $totalSize = count($keys) * 1024; // Rough estimate: 1KB per key
            }
        } catch (Exception $e) {
            // Fallback estimation
            $totalSize = count($keys) * 1024;
        }
        
        // Estimate total based on sample
        if (count($keys) > 100) {
            $totalSize = ($totalSize / 100) * count($keys);
        }
        
        return $this->formatBytes($totalSize);
    }

    /**
     * Format bytes to human readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}