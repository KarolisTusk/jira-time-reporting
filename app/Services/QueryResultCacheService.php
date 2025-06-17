<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueryResultCacheService
{
    private const CACHE_PREFIX = 'query_result:';
    private const DEFAULT_TTL = 1800; // 30 minutes
    private const CACHE_TAGS = ['query_results'];
    
    // Different TTL values based on query type
    private const TTL_MAPPING = [
        'stats' => 600,         // 10 minutes - frequently updated stats
        'breakdown' => 900,     // 15 minutes - resource breakdowns
        'trend' => 1800,        // 30 minutes - time trend data
        'user_data' => 1200,    // 20 minutes - user productivity data
        'project_summary' => 600, // 10 minutes - project summaries
        'recent_activity' => 300, // 5 minutes - recent activity
        'aggregations' => 900,  // 15 minutes - general aggregations
    ];

    /**
     * Cache a query result with automatic key generation.
     */
    public function cacheQueryResult(string $queryType, array $params, $result, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey($queryType, $params);
        $cacheTTL = $ttl ?? (self::TTL_MAPPING[$queryType] ?? self::DEFAULT_TTL);
        
        $cacheData = [
            'result' => $result,
            'cached_at' => now(),
            'query_type' => $queryType,
            'params' => $params,
            'ttl' => $cacheTTL,
        ];
        
        Cache::store('redis')->put($cacheKey, $cacheData, $cacheTTL);
        
        Log::debug("Cached query result: {$queryType}", [
            'cache_key' => $cacheKey,
            'ttl' => $cacheTTL,
            'result_size' => $this->calculateResultSize($result)
        ]);
    }

    /**
     * Get cached query result.
     */
    public function getCachedQueryResult(string $queryType, array $params)
    {
        $cacheKey = $this->generateCacheKey($queryType, $params);
        
        $cached = Cache::store('redis')->get($cacheKey);
        
        if ($cached) {
            Log::debug("Retrieved cached query result: {$queryType}", [
                'cache_key' => $cacheKey,
                'cached_at' => $cached['cached_at'] ?? null
            ]);
            
            return $cached['result'];
        }
        
        return null;
    }

    /**
     * Remember a query result (cache if not exists, return if exists).
     */
    public function rememberQueryResult(string $queryType, array $params, callable $callback, ?int $ttl = null)
    {
        $cached = $this->getCachedQueryResult($queryType, $params);
        
        if ($cached !== null) {
            return $cached;
        }
        
        $result = $callback();
        $this->cacheQueryResult($queryType, $params, $result, $ttl);
        
        return $result;
    }

    /**
     * Cache database query result with SQL fingerprinting.
     */
    public function cacheDbQueryResult(Builder $query, string $queryType, array $context = [], ?int $ttl = null)
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        
        $cacheKey = $this->generateDbQueryCacheKey($sql, $bindings, $queryType, $context);
        $cacheTTL = $ttl ?? (self::TTL_MAPPING[$queryType] ?? self::DEFAULT_TTL);
        
        $cached = Cache::store('redis')->get($cacheKey);
        
        if ($cached) {
            Log::debug("Using cached DB query result", [
                'query_type' => $queryType,
                'cache_key' => $cacheKey
            ]);
            return $cached['result'];
        }
        
        // Execute the query
        $startTime = microtime(true);
        $result = $query->get();
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        // Cache the result
        $cacheData = [
            'result' => $result,
            'cached_at' => now(),
            'query_type' => $queryType,
            'sql_fingerprint' => md5($sql),
            'execution_time_ms' => round($executionTime, 2),
            'context' => $context,
        ];
        
        Cache::store('redis')->put($cacheKey, $cacheData, $cacheTTL);
        
        Log::debug("Cached DB query result", [
            'query_type' => $queryType,
            'execution_time_ms' => round($executionTime, 2),
            'result_count' => $result->count(),
            'cache_key' => $cacheKey,
            'ttl' => $cacheTTL
        ]);
        
        return $result;
    }

    /**
     * Invalidate query cache by type and/or project.
     */
    public function invalidateQueryCache(string $queryType = null, string $projectKey = null): void
    {
        $patterns = [];
        
        if ($queryType && $projectKey) {
            $patterns[] = self::CACHE_PREFIX . "{$queryType}:*{$projectKey}*";
        } elseif ($queryType) {
            $patterns[] = self::CACHE_PREFIX . "{$queryType}:*";
        } elseif ($projectKey) {
            $patterns[] = self::CACHE_PREFIX . "*{$projectKey}*";
        } else {
            $patterns[] = self::CACHE_PREFIX . "*";
        }
        
        foreach ($patterns as $pattern) {
            $keys = $this->getCacheKeysByPattern($pattern);
            if (!empty($keys)) {
                Cache::store('redis')->deleteMultiple($keys);
                Log::info("Invalidated query cache", [
                    'pattern' => $pattern,
                    'keys_deleted' => count($keys)
                ]);
            }
        }
    }

    /**
     * Warm up cache for common queries.
     */
    public function warmUpQueryCache(array $projectKeys = [], array $queryTypes = []): array
    {
        $defaultQueryTypes = ['stats', 'breakdown', 'recent_activity'];
        $queryTypes = !empty($queryTypes) ? $queryTypes : $defaultQueryTypes;
        
        $results = [];
        
        foreach ($projectKeys as $projectKey) {
            foreach ($queryTypes as $queryType) {
                try {
                    $this->warmUpSpecificQuery($queryType, $projectKey);
                    $results[$projectKey][$queryType] = 'success';
                } catch (\Exception $e) {
                    $results[$projectKey][$queryType] = 'failed: ' . $e->getMessage();
                    Log::warning("Failed to warm up query cache", [
                        'project' => $projectKey,
                        'query_type' => $queryType,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $results;
    }

    /**
     * Get cache statistics for monitoring.
     */
    public function getCacheStatistics(): array
    {
        $keys = $this->getCacheKeysByPattern(self::CACHE_PREFIX . '*');
        
        $stats = [
            'total_cached_queries' => count($keys),
            'cache_size_estimation' => $this->estimateCacheSize($keys),
            'query_type_breakdown' => [],
            'cache_hit_analysis' => $this->analyzeCacheHits($keys),
        ];
        
        // Analyze by query type
        foreach ($keys as $key) {
            $keyParts = explode(':', $key);
            $queryType = $keyParts[2] ?? 'unknown';
            
            if (!isset($stats['query_type_breakdown'][$queryType])) {
                $stats['query_type_breakdown'][$queryType] = 0;
            }
            $stats['query_type_breakdown'][$queryType]++;
        }
        
        return $stats;
    }

    /**
     * Generate cache key for query result.
     */
    private function generateCacheKey(string $queryType, array $params): string
    {
        ksort($params); // Ensure consistent ordering
        $paramHash = md5(json_encode($params));
        return self::CACHE_PREFIX . "{$queryType}:{$paramHash}";
    }

    /**
     * Generate cache key for database query.
     */
    private function generateDbQueryCacheKey(string $sql, array $bindings, string $queryType, array $context): string
    {
        $combined = [
            'sql' => $sql,
            'bindings' => $bindings,
            'context' => $context
        ];
        
        $hash = md5(json_encode($combined));
        return self::CACHE_PREFIX . "db:{$queryType}:{$hash}";
    }

    /**
     * Calculate result size for logging.
     */
    private function calculateResultSize($result): string
    {
        if (is_array($result)) {
            return count($result) . ' items';
        } elseif (is_object($result) && method_exists($result, 'count')) {
            return $result->count() . ' items';
        } elseif (is_string($result)) {
            return strlen($result) . ' chars';
        } else {
            return 'unknown';
        }
    }

    /**
     * Get cache keys by pattern.
     */
    private function getCacheKeysByPattern(string $pattern): array
    {
        $redis = Cache::store('redis')->getRedis();
        return $redis->keys($pattern) ?? [];
    }

    /**
     * Warm up specific query type for a project.
     */
    private function warmUpSpecificQuery(string $queryType, string $projectKey): void
    {
        $now = now();
        $oneWeekAgo = $now->copy()->subWeek();
        
        switch ($queryType) {
            case 'stats':
                $params = ['project_key' => $projectKey, 'start_date' => $oneWeekAgo->format('Y-m-d')];
                // Simulate stats query
                $this->cacheQueryResult($queryType, $params, ['cached' => true, 'warmed_up' => true]);
                break;
                
            case 'breakdown':
                $params = ['project_key' => $projectKey, 'type' => 'resource', 'period' => 'week'];
                $this->cacheQueryResult($queryType, $params, ['cached' => true, 'warmed_up' => true]);
                break;
                
            case 'recent_activity':
                $params = ['project_key' => $projectKey, 'limit' => 50];
                $this->cacheQueryResult($queryType, $params, ['cached' => true, 'warmed_up' => true]);
                break;
        }
    }

    /**
     * Estimate cache size.
     */
    private function estimateCacheSize(array $keys): string
    {
        if (empty($keys)) {
            return '0 B';
        }
        
        $redis = Cache::store('redis')->getRedis();
        $totalSize = 0;
        
        // Sample first 50 keys to estimate
        $sampleKeys = array_slice($keys, 0, 50);
        
        foreach ($sampleKeys as $key) {
            $size = $redis->memory('usage', $key) ?? 0;
            $totalSize += $size;
        }
        
        // Extrapolate for all keys
        if (count($keys) > 50) {
            $avgSize = $totalSize / count($sampleKeys);
            $totalSize = $avgSize * count($keys);
        }
        
        return $this->formatBytes($totalSize);
    }

    /**
     * Analyze cache hit patterns.
     */
    private function analyzeCacheHits(array $keys): array
    {
        $redis = Cache::store('redis')->getRedis();
        $analysis = [
            'fresh_entries' => 0,  // < 5 minutes
            'warm_entries' => 0,   // 5-30 minutes  
            'cold_entries' => 0,   // > 30 minutes
        ];
        
        $now = now();
        
        foreach (array_slice($keys, 0, 100) as $key) { // Sample for performance
            $data = $redis->get($key);
            if ($data) {
                $cached = json_decode($data, true);
                if (isset($cached['cached_at'])) {
                    $cachedAt = Carbon::parse($cached['cached_at']);
                    $ageMinutes = $now->diffInMinutes($cachedAt);
                    
                    if ($ageMinutes < 5) {
                        $analysis['fresh_entries']++;
                    } elseif ($ageMinutes < 30) {
                        $analysis['warm_entries']++;
                    } else {
                        $analysis['cold_entries']++;
                    }
                }
            }
        }
        
        return $analysis;
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