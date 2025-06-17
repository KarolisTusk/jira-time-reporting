<?php

namespace App\Console\Commands;

use App\Services\JiraSyncCacheService;
use Illuminate\Console\Command;

class ManageJiraSyncCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:cache:manage 
                            {action : Action to perform: stats, clear, clear-project}
                            {--project= : Project key for project-specific actions}
                            {--force : Force action without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage JIRA sync cache (view statistics, clear cache)';

    /**
     * Execute the console command.
     */
    public function handle(JiraSyncCacheService $cacheService): int
    {
        $action = $this->argument('action');
        
        return match ($action) {
            'stats' => $this->showCacheStatistics($cacheService),
            'clear' => $this->clearAllCache($cacheService),
            'clear-project' => $this->clearProjectCache($cacheService),
            default => $this->handleInvalidAction($action)
        };
    }
    
    /**
     * Show cache statistics.
     */
    private function showCacheStatistics(JiraSyncCacheService $cacheService): int
    {
        $this->info('📊 JIRA Sync Cache Statistics');
        $this->newLine();
        
        try {
            $stats = $cacheService->getCacheStats();
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Cache Keys', number_format($stats['total_keys'])],
                    ['Worklog Cache Keys', number_format($stats['worklog_cache_keys'])],
                    ['API Cache Keys', number_format($stats['api_cache_keys'])],
                    ['Metrics Cache Keys', number_format($stats['metrics_cache_keys'])],
                    ['Summary Cache Keys', number_format($stats['summary_cache_keys'])],
                    ['Estimated Memory Usage', $stats['cache_memory_usage']],
                ]
            );
            
            // Cache efficiency metrics
            $totalDataKeys = $stats['worklog_cache_keys'] + $stats['api_cache_keys'] + $stats['summary_cache_keys'];
            $efficiencyRatio = $stats['total_keys'] > 0 ? ($totalDataKeys / $stats['total_keys']) * 100 : 0;
            
            $this->newLine();
            $this->info("Cache Efficiency: " . number_format($efficiencyRatio, 1) . "% (data keys vs total keys)");
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to retrieve cache statistics: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Clear all JIRA sync cache.
     */
    private function clearAllCache(JiraSyncCacheService $cacheService): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will clear ALL JIRA sync cache data. Continue?')) {
                $this->info('Cache clear operation cancelled.');
                return self::SUCCESS;
            }
        }
        
        try {
            $this->info('Clearing all JIRA sync cache...');
            $cacheService->invalidateAllSyncCache();
            $this->info('✅ All JIRA sync cache cleared successfully!');
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to clear cache: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Clear cache for a specific project.
     */
    private function clearProjectCache(JiraSyncCacheService $cacheService): int
    {
        $projectKey = $this->option('project');
        
        if (!$projectKey) {
            $this->error('Project key is required for project-specific cache clearing. Use --project=PROJECT_KEY');
            return self::FAILURE;
        }
        
        if (!$this->option('force')) {
            if (!$this->confirm("This will clear cache for project '{$projectKey}'. Continue?")) {
                $this->info('Cache clear operation cancelled.');
                return self::SUCCESS;
            }
        }
        
        try {
            $this->info("Clearing cache for project: {$projectKey}");
            $cacheService->invalidateProjectCache($projectKey);
            $this->info("✅ Cache cleared for project '{$projectKey}' successfully!");
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to clear project cache: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Handle invalid action.
     */
    private function handleInvalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->newLine();
        $this->info('Available actions:');
        $this->line('  stats         - Show cache statistics');
        $this->line('  clear         - Clear all JIRA sync cache');
        $this->line('  clear-project - Clear cache for specific project (requires --project)');
        $this->newLine();
        $this->info('Examples:');
        $this->line('  php artisan jira:cache:manage stats');
        $this->line('  php artisan jira:cache:manage clear --force');
        $this->line('  php artisan jira:cache:manage clear-project --project=DEMO');
        
        return self::FAILURE;
    }
}
