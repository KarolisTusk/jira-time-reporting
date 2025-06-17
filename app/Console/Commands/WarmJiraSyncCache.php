<?php

namespace App\Console\Commands;

use App\Models\JiraProject;
use App\Services\JiraSyncCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WarmJiraSyncCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:cache:warm 
                            {--projects=* : Specific project keys to warm cache for}
                            {--all : Warm cache for all projects}
                            {--stats : Show cache statistics after warming}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up the JIRA sync cache with frequently accessed data';

    /**
     * Execute the console command.
     */
    public function handle(JiraSyncCacheService $cacheService): int
    {
        $this->info('Starting JIRA sync cache warming...');
        
        $projectKeys = $this->getProjectKeys();
        
        if (empty($projectKeys)) {
            $this->error('No projects found to warm cache for.');
            return self::FAILURE;
        }
        
        $this->info("Warming cache for " . count($projectKeys) . " projects: " . implode(', ', $projectKeys));
        
        $progressBar = $this->output->createProgressBar(count($projectKeys));
        $progressBar->start();
        
        foreach ($projectKeys as $projectKey) {
            try {
                $this->warmProjectCache($cacheService, $projectKey);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to warm cache for project {$projectKey}: " . $e->getMessage());
                Log::error("Cache warming failed for project {$projectKey}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        // Warm up the cache using the service method
        try {
            $cacheService->warmUpCache($projectKeys);
            $this->info('✅ Cache warming completed successfully!');
        } catch (\Exception $e) {
            $this->error("Cache warming service failed: " . $e->getMessage());
            return self::FAILURE;
        }
        
        // Show cache statistics if requested
        if ($this->option('stats')) {
            $this->showCacheStatistics($cacheService);
        }
        
        return self::SUCCESS;
    }
    
    /**
     * Get project keys to warm cache for.
     */
    private function getProjectKeys(): array
    {
        if ($this->option('all')) {
            return JiraProject::pluck('key')->toArray();
        }
        
        $specifiedProjects = $this->option('projects');
        if (!empty($specifiedProjects)) {
            // Validate that specified projects exist
            $existingProjects = JiraProject::whereIn('key', $specifiedProjects)->pluck('key')->toArray();
            $missingProjects = array_diff($specifiedProjects, $existingProjects);
            
            if (!empty($missingProjects)) {
                $this->warn('Some specified projects do not exist: ' . implode(', ', $missingProjects));
            }
            
            return $existingProjects;
        }
        
        // Default: warm cache for recently active projects (have worklogs in last 30 days)
        return JiraProject::whereHas('issues.worklogs', function ($query) {
            $query->where('created_at', '>=', now()->subDays(30));
        })->pluck('key')->toArray();
    }
    
    /**
     * Warm cache for a specific project.
     */
    private function warmProjectCache(JiraSyncCacheService $cacheService, string $projectKey): void
    {
        // The actual cache warming is done by the service
        // This method could be extended to do project-specific warming
        $this->line("  Warming cache for project: {$projectKey}", 'comment');
    }
    
    /**
     * Show cache statistics.
     */
    private function showCacheStatistics(JiraSyncCacheService $cacheService): void
    {
        $this->newLine();
        $this->info('📊 Cache Statistics:');
        
        try {
            $stats = $cacheService->getCacheStats();
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Cache Keys', $stats['total_keys']],
                    ['Worklog Cache Keys', $stats['worklog_cache_keys']],
                    ['API Cache Keys', $stats['api_cache_keys']],
                    ['Metrics Cache Keys', $stats['metrics_cache_keys']],
                    ['Summary Cache Keys', $stats['summary_cache_keys']],
                    ['Estimated Memory Usage', $stats['cache_memory_usage']],
                ]
            );
        } catch (\Exception $e) {
            $this->error("Failed to retrieve cache statistics: " . $e->getMessage());
        }
    }
}
