<?php

namespace App\Http\Middleware;

use App\Services\JiraSyncCacheService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class JiraCacheMonitoringMiddleware
{
    protected JiraSyncCacheService $cacheService;

    public function __construct(JiraSyncCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $initialCacheStats = null;
        
        // Only monitor cache for JIRA-related routes
        if ($this->shouldMonitorCache($request)) {
            try {
                $initialCacheStats = $this->cacheService->getCacheStats();
            } catch (\Exception $e) {
                Log::warning('Failed to get initial cache stats for monitoring', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $response = $next($request);
        
        if ($initialCacheStats) {
            $this->logCachePerformance($request, $startTime, $initialCacheStats);
        }
        
        return $response;
    }
    
    /**
     * Determine if we should monitor cache for this request.
     */
    private function shouldMonitorCache(Request $request): bool
    {
        $path = $request->path();
        
        // Monitor JIRA sync related routes
        $monitoredPaths = [
            'admin/jira-sync',
            'admin/enhanced-jira-sync',
            'api/jira',
            'jira/sync',
            'sync',
        ];
        
        foreach ($monitoredPaths as $monitoredPath) {
            if (str_starts_with($path, $monitoredPath)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Log cache performance metrics.
     */
    private function logCachePerformance(Request $request, float $startTime, array $initialStats): void
    {
        try {
            $endTime = microtime(true);
            $requestDuration = ($endTime - $startTime) * 1000; // Convert to milliseconds
            
            $finalStats = $this->cacheService->getCacheStats();
            
            // Calculate cache activity during request
            $cacheActivity = [
                'keys_added' => max(0, $finalStats['total_keys'] - $initialStats['total_keys']),
                'memory_change' => $this->calculateMemoryChange($initialStats['cache_memory_usage'], $finalStats['cache_memory_usage']),
                'request_duration_ms' => round($requestDuration, 2),
                'route' => $request->path(),
                'method' => $request->method(),
            ];
            
            // Only log if there was significant cache activity
            if ($cacheActivity['keys_added'] > 0 || abs($this->parseMemoryBytes($cacheActivity['memory_change'])) > 1024) {
                Log::info('JIRA Cache Activity', array_merge($cacheActivity, [
                    'final_total_keys' => $finalStats['total_keys'],
                    'final_memory_usage' => $finalStats['cache_memory_usage'],
                ]));
            }
            
            // Log slow requests that might benefit from more caching
            if ($requestDuration > 5000) { // 5 seconds
                Log::warning('Slow JIRA request detected - consider additional caching', [
                    'route' => $request->path(),
                    'duration_ms' => round($requestDuration, 2),
                    'cache_keys_used' => $finalStats['total_keys'],
                ]);
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to log cache performance metrics', [
                'error' => $e->getMessage(),
                'route' => $request->path()
            ]);
        }
    }
    
    /**
     * Calculate memory change between two memory usage strings.
     */
    private function calculateMemoryChange(string $initial, string $final): string
    {
        $initialBytes = $this->parseMemoryBytes($initial);
        $finalBytes = $this->parseMemoryBytes($final);
        $diffBytes = $finalBytes - $initialBytes;
        
        if ($diffBytes >= 0) {
            return '+' . $this->formatBytes($diffBytes);
        } else {
            return '-' . $this->formatBytes(abs($diffBytes));
        }
    }
    
    /**
     * Parse memory usage string to bytes.
     */
    private function parseMemoryBytes(string $memoryStr): int
    {
        if (preg_match('/^([+-]?)(\d+(?:\.\d+)?)\s*([KMGT]?B)$/i', trim($memoryStr), $matches)) {
            $sign = $matches[1] === '-' ? -1 : 1;
            $value = (float) $matches[2];
            $unit = strtoupper($matches[3]);
            
            $multipliers = [
                'B' => 1,
                'KB' => 1024,
                'MB' => 1024 * 1024,
                'GB' => 1024 * 1024 * 1024,
                'TB' => 1024 * 1024 * 1024 * 1024,
            ];
            
            return (int) ($sign * $value * ($multipliers[$unit] ?? 1));
        }
        
        return 0;
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
