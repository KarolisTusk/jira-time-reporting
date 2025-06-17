<?php

namespace App\Console\Commands;

use App\Services\JiraApiResponseCacheService;
use Illuminate\Console\Command;

class ManageJiraApiCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jira:api-cache:manage 
                            {action : Action to perform (stats|clear|warm|cleanup)}
                            {--project= : Project key for project-specific operations}
                            {--endpoint= : Specific endpoint for endpoint-specific operations}
                            {--force : Force operation without confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Manage JIRA API response cache (PRD: API performance optimization)';

    /**
     * Execute the console command.
     */
    public function handle(JiraApiResponseCacheService $cacheService): int
    {
        $action = $this->argument('action');
        $projectKey = $this->option('project');
        $endpoint = $this->option('endpoint');
        $force = $this->option('force');

        $this->info("JIRA API Cache Management - Action: {$action}");
        $this->newLine();

        switch ($action) {
            case 'stats':
                return $this->showCacheStatistics($cacheService);
                
            case 'clear':
                return $this->clearCache($cacheService, $projectKey, $endpoint, $force);
                
            case 'warm':
                return $this->warmCache($cacheService, $projectKey);
                
            case 'cleanup':
                return $this->cleanupCache($cacheService, $force);
                
            default:
                $this->error("Unknown action: {$action}");
                $this->info('Available actions: stats, clear, warm, cleanup');
                return self::FAILURE;
        }
    }

    /**
     * Display cache statistics.
     */
    private function showCacheStatistics(JiraApiResponseCacheService $cacheService): int
    {
        $this->info('📊 JIRA API Cache Statistics');
        $this->newLine();

        try {
            $stats = $cacheService->getCacheStatistics();
            
            // Overview
            $this->info('🔍 Overview:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Cached Responses', number_format($stats['total_cached_responses'])],
                    ['Cache Size (MB)', $stats['cache_size_mb']],
                    ['Fresh Entries (< 15 min)', $stats['age_distribution']['fresh']],
                    ['Moderate Entries (15-60 min)', $stats['age_distribution']['moderate']],
                    ['Old Entries (> 60 min)', $stats['age_distribution']['old']],
                ]
            );
            
            $this->newLine();
            
            // Data types breakdown
            if (!empty($stats['data_types'])) {
                $this->info('📁 Data Types Distribution:');
                $dataTypeRows = [];
                foreach ($stats['data_types'] as $type => $count) {
                    $percentage = round(($count / $stats['total_cached_responses']) * 100, 1);
                    $dataTypeRows[] = [$type, $count, "{$percentage}%"];
                }
                $this->table(['Data Type', 'Count', 'Percentage'], $dataTypeRows);
                $this->newLine();
            }
            
            // Top endpoints
            if (!empty($stats['endpoints'])) {
                $this->info('🎯 Top Cached Endpoints:');
                arsort($stats['endpoints']);
                $endpointRows = [];
                $displayLimit = 10; // Show top 10 endpoints
                $count = 0;
                
                foreach ($stats['endpoints'] as $endpoint => $requests) {
                    if ($count >= $displayLimit) break;
                    $percentage = round(($requests / $stats['total_cached_responses']) * 100, 1);
                    $endpointRows[] = [
                        $count + 1,
                        $this->truncateEndpoint($endpoint),
                        $requests,
                        "{$percentage}%"
                    ];
                    $count++;
                }
                
                $this->table(['#', 'Endpoint', 'Cached Responses', 'Percentage'], $endpointRows);
            }
            
            // Performance indicators
            $this->newLine();
            $this->info('📈 Performance Indicators:');
            $freshPercentage = $stats['total_cached_responses'] > 0 
                ? round(($stats['age_distribution']['fresh'] / $stats['total_cached_responses']) * 100, 1) 
                : 0;
            $cacheEfficiency = $freshPercentage > 70 ? '🟢 Excellent' : ($freshPercentage > 40 ? '🟡 Good' : '🔴 Needs optimization');
            
            $this->table(
                ['Indicator', 'Value', 'Status'],
                [
                    ['Fresh Cache Percentage', "{$freshPercentage}%", $cacheEfficiency],
                    ['Average Cache Size per Response', round($stats['cache_size_mb'] / max($stats['total_cached_responses'], 1) * 1024, 2) . ' KB', $stats['cache_size_mb'] < 100 ? '🟢 Optimal' : '🟡 Monitor'],
                    ['PRD Compliance', $stats['total_cached_responses'] > 0 ? '🟢 Active' : '🔴 Inactive', $stats['total_cached_responses'] > 0 ? 'Caching enabled' : 'No cache activity']
                ]
            );
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to retrieve cache statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Clear cache entries.
     */
    private function clearCache(JiraApiResponseCacheService $cacheService, ?string $projectKey, ?string $endpoint, bool $force): int
    {
        $scope = 'all cache entries';
        
        if ($projectKey) {
            $scope = "cache entries for project '{$projectKey}'";
        } elseif ($endpoint) {
            $scope = "cache entries for endpoint '{$endpoint}'";
        }
        
        if (!$force && !$this->confirm("Are you sure you want to clear {$scope}?")) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }
        
        $this->info("🧹 Clearing {$scope}...");
        
        try {
            $clearedCount = 0;
            
            if ($projectKey) {
                $clearedCount = $cacheService->invalidateProjectApiCache($projectKey);
                $this->info("✅ Cleared {$clearedCount} cache entries for project '{$projectKey}'");
            } elseif ($endpoint) {
                $clearedCount = $cacheService->invalidateEndpointCache($endpoint);
                $this->info("✅ Cleared {$clearedCount} cache entries for endpoint '{$endpoint}'");
            } else {
                // Clear all - would need to implement in service
                $clearedCount = $cacheService->cleanupExpiredCache();
                $this->info("✅ Cleared {$clearedCount} expired cache entries");
                $this->warn('Note: Complete cache clearing not implemented. Only expired entries were cleared.');
            }
            
            if ($clearedCount === 0) {
                $this->warn('No cache entries found to clear.');
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to clear cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Warm cache for project.
     */
    private function warmCache(JiraApiResponseCacheService $cacheService, ?string $projectKey): int
    {
        if (!$projectKey) {
            $this->error('Project key is required for cache warming. Use --project=PROJECT_KEY');
            return self::FAILURE;
        }
        
        $this->info("🔥 Warming API cache for project '{$projectKey}'...");
        
        try {
            $this->withProgressBar(['Preparing endpoints...'], function () use ($cacheService, $projectKey) {
                $results = $cacheService->warmApiCache($projectKey);
                
                $this->newLine(2);
                $this->info('📋 Cache Warming Results:');
                
                $rows = [];
                foreach ($results as $type => $result) {
                    $status = $result['status'] === 'prepared' ? '🟢 Ready' : '🔴 Failed';
                    $rows[] = [
                        ucfirst($type),
                        $this->truncateEndpoint($result['endpoint']),
                        $status
                    ];
                }
                
                $this->table(['Type', 'Endpoint', 'Status'], $rows);
            });
            
            $this->newLine();
            $this->info("✅ Cache warming completed for project '{$projectKey}'");
            $this->warn('Note: Actual cache population will occur during next API calls.');
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to warm cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Cleanup expired cache entries.
     */
    private function cleanupCache(JiraApiResponseCacheService $cacheService, bool $force): int
    {
        if (!$force && !$this->confirm('Are you sure you want to cleanup expired cache entries?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }
        
        $this->info('🧽 Cleaning up expired cache entries...');
        
        try {
            $cleanedCount = $cacheService->cleanupExpiredCache();
            
            if ($cleanedCount > 0) {
                $this->info("✅ Cleaned up {$cleanedCount} expired cache entries");
            } else {
                $this->info('✅ No expired cache entries found');
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to cleanup cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Truncate endpoint for display.
     */
    private function truncateEndpoint(string $endpoint): string
    {
        return strlen($endpoint) > 50 ? substr($endpoint, 0, 47) . '...' : $endpoint;
    }
}