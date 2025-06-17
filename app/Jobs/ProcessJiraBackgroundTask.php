<?php

namespace App\Jobs;

use App\Services\JiraSyncCacheService;
use App\Services\QueryResultCacheService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessJiraBackgroundTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes for background tasks
    public int $tries = 2; // Limited retries for background tasks
    public int $maxExceptions = 2;

    protected string $taskType;
    protected array $taskOptions;
    protected string $taskId;

    /**
     * Create a new background task job (PRD: maintenance and optimization tasks).
     */
    public function __construct(string $taskType, array $taskOptions = [])
    {
        $this->taskType = $taskType;
        $this->taskOptions = $taskOptions;
        $this->taskId = uniqid('bg_task_');
        
        // Use background queue for non-critical operations
        $this->onQueue('jira-background');
    }

    /**
     * Execute the background task (PRD: maintenance, cleanup, and optimization).
     */
    public function handle(JiraSyncCacheService $cacheService, QueryResultCacheService $queryCache): void
    {
        $startTime = microtime(true);
        
        Log::info('Background JIRA task started', [
            'task_id' => $this->taskId,
            'task_type' => $this->taskType,
            'options' => $this->taskOptions,
            'queue' => 'jira-background'
        ]);

        try {
            // Execute task based on type
            switch ($this->taskType) {
                case 'cache_cleanup':
                    $this->handleCacheCleanup($cacheService);
                    break;
                    
                case 'cache_warming':
                    $this->handleCacheWarming($cacheService);
                    break;
                    
                case 'database_optimization':
                    $this->handleDatabaseOptimization();
                    break;
                    
                case 'sync_history_cleanup':
                    $this->handleSyncHistoryCleanup();
                    break;
                    
                case 'generate_monthly_report':
                    $this->handleMonthlyReportGeneration($queryCache);
                    break;
                    
                case 'validate_data_integrity':
                    $this->handleDataIntegrityValidation();
                    break;
                    
                default:
                    throw new Exception("Unknown background task type: {$this->taskType}");
            }
            
            $duration = microtime(true) - $startTime;
            $durationMinutes = round($duration / 60, 2);
            
            Log::info('Background JIRA task completed', [
                'task_id' => $this->taskId,
                'task_type' => $this->taskType,
                'duration_minutes' => $durationMinutes,
                'success' => true
            ]);

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            Log::error('Background JIRA task failed', [
                'task_id' => $this->taskId,
                'task_type' => $this->taskType,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 2),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries
            ]);

            throw $e;
        }
    }

    /**
     * Handle cache cleanup task.
     */
    private function handleCacheCleanup(JiraSyncCacheService $cacheService): void
    {
        $maxAge = $this->taskOptions['max_age_hours'] ?? 24;
        $forceCleanup = $this->taskOptions['force'] ?? false;
        
        Log::info('Starting cache cleanup', [
            'task_id' => $this->taskId,
            'max_age_hours' => $maxAge,
            'force_cleanup' => $forceCleanup
        ]);

        // Clean expired cache entries
        $cleanedEntries = $cacheService->cleanupExpiredCache($maxAge, $forceCleanup);
        
        Log::info('Cache cleanup completed', [
            'task_id' => $this->taskId,
            'cleaned_entries' => $cleanedEntries,
            'max_age_hours' => $maxAge
        ]);
    }

    /**
     * Handle cache warming task.
     */
    private function handleCacheWarming(JiraSyncCacheService $cacheService): void
    {
        $projectKeys = $this->taskOptions['project_keys'] ?? [];
        $warmAll = $this->taskOptions['warm_all'] ?? false;
        
        Log::info('Starting cache warming', [
            'task_id' => $this->taskId,
            'project_keys' => $projectKeys,
            'warm_all' => $warmAll
        ]);

        if ($warmAll) {
            // Warm cache for all active projects
            $activeProjects = DB::table('jira_projects')
                ->where('is_active', true)
                ->pluck('key')
                ->toArray();
            $projectKeys = array_merge($projectKeys, $activeProjects);
        }

        $warmedProjects = 0;
        foreach (array_unique($projectKeys) as $projectKey) {
            try {
                $cacheService->warmProjectCache($projectKey, [
                    'recent_worklogs' => true,
                    'project_summary' => true,
                    'resource_breakdown' => true
                ]);
                $warmedProjects++;
            } catch (Exception $e) {
                Log::warning('Failed to warm cache for project', [
                    'project_key' => $projectKey,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Cache warming completed', [
            'task_id' => $this->taskId,
            'warmed_projects' => $warmedProjects,
            'total_projects' => count($projectKeys)
        ]);
    }

    /**
     * Handle database optimization task.
     */
    private function handleDatabaseOptimization(): void
    {
        Log::info('Starting database optimization', [
            'task_id' => $this->taskId
        ]);

        $optimizationResults = [];

        // Analyze table statistics
        $tables = ['jira_worklogs', 'jira_issues', 'jira_sync_histories'];
        foreach ($tables as $table) {
            try {
                DB::statement("ANALYZE {$table}");
                $optimizationResults[$table] = 'analyzed';
            } catch (Exception $e) {
                Log::warning("Failed to analyze table {$table}", [
                    'error' => $e->getMessage()
                ]);
                $optimizationResults[$table] = 'failed';
            }
        }

        // Clean up old sync history records (keep last 30 days)
        $cutoffDate = now()->subDays(30);
        $deletedSyncRecords = DB::table('jira_sync_histories')
            ->where('created_at', '<', $cutoffDate)
            ->where('status', '!=', 'in_progress')
            ->delete();
            
        $optimizationResults['sync_history_cleanup'] = $deletedSyncRecords;

        // Clean up orphaned sync checkpoints
        $deletedCheckpoints = DB::table('jira_sync_checkpoints')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('jira_sync_histories')
                    ->whereColumn('jira_sync_histories.id', 'jira_sync_checkpoints.sync_history_id');
            })
            ->delete();
            
        $optimizationResults['orphaned_checkpoints_cleanup'] = $deletedCheckpoints;
        
        Log::info('Database optimization completed', [
            'task_id' => $this->taskId,
            'results' => $optimizationResults
        ]);
    }

    /**
     * Handle sync history cleanup task.
     */
    private function handleSyncHistoryCleanup(): void
    {
        $retentionDays = $this->taskOptions['retention_days'] ?? 30;
        $keepCompleted = $this->taskOptions['keep_completed'] ?? true;
        
        Log::info('Starting sync history cleanup', [
            'task_id' => $this->taskId,
            'retention_days' => $retentionDays,
            'keep_completed' => $keepCompleted
        ]);

        $cutoffDate = now()->subDays($retentionDays);
        
        $query = DB::table('jira_sync_histories')
            ->where('created_at', '<', $cutoffDate);
            
        if ($keepCompleted) {
            $query->where('status', '!=', 'completed');
        }
        
        $deletedRecords = $query->delete();
        
        Log::info('Sync history cleanup completed', [
            'task_id' => $this->taskId,
            'deleted_records' => $deletedRecords,
            'retention_days' => $retentionDays
        ]);
    }

    /**
     * Handle monthly report generation task.
     */
    private function handleMonthlyReportGeneration(QueryResultCacheService $queryCache): void
    {
        $reportMonth = $this->taskOptions['month'] ?? now()->subMonth()->format('Y-m');
        $projectKeys = $this->taskOptions['project_keys'] ?? [];
        
        Log::info('Starting monthly report generation', [
            'task_id' => $this->taskId,
            'report_month' => $reportMonth,
            'project_keys' => $projectKeys
        ]);

        // Generate and cache monthly report data
        $reportData = $queryCache->rememberQueryResult(
            'monthly_report',
            ['month' => $reportMonth, 'projects' => $projectKeys],
            function() use ($reportMonth, $projectKeys) {
                // Generate comprehensive monthly report
                $startDate = Carbon::createFromFormat('Y-m', $reportMonth)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
                
                $query = DB::table('jira_worklogs')
                    ->whereBetween('started', [$startDate, $endDate]);
                    
                if (!empty($projectKeys)) {
                    $query->whereIn('project_key', $projectKeys);
                }
                
                return [
                    'total_hours' => $query->sum('time_spent_seconds') / 3600,
                    'total_worklogs' => $query->count(),
                    'unique_users' => $query->distinct('author_account_id')->count(),
                    'projects_breakdown' => $query->groupBy('project_key')
                        ->selectRaw('project_key, SUM(time_spent_seconds) / 3600 as hours, COUNT(*) as worklogs')
                        ->get()
                        ->toArray(),
                    'resource_breakdown' => $query->groupBy('resource_type')
                        ->selectRaw('resource_type, SUM(time_spent_seconds) / 3600 as hours, COUNT(*) as worklogs')
                        ->get()
                        ->toArray(),
                    'generated_at' => now()->toISOString(),
                    'report_period' => $reportMonth
                ];
            },
            86400 // Cache for 24 hours
        );
        
        Log::info('Monthly report generation completed', [
            'task_id' => $this->taskId,
            'report_month' => $reportMonth,
            'total_hours' => $reportData['total_hours'],
            'total_worklogs' => $reportData['total_worklogs']
        ]);
    }

    /**
     * Handle data integrity validation task.
     */
    private function handleDataIntegrityValidation(): void
    {
        Log::info('Starting data integrity validation', [
            'task_id' => $this->taskId
        ]);

        $validationResults = [];

        // Check for orphaned worklogs
        $orphanedWorklogs = DB::table('jira_worklogs')
            ->leftJoin('jira_issues', 'jira_worklogs.issue_id', '=', 'jira_issues.id')
            ->whereNull('jira_issues.id')
            ->count();
            
        $validationResults['orphaned_worklogs'] = $orphanedWorklogs;

        // Check for duplicate worklogs
        $duplicateWorklogs = DB::table('jira_worklogs')
            ->select('jira_worklog_id')
            ->groupBy('jira_worklog_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
            
        $validationResults['duplicate_worklogs'] = $duplicateWorklogs;

        // Check for issues without projects
        $orphanedIssues = DB::table('jira_issues')
            ->leftJoin('jira_projects', 'jira_issues.project_key', '=', 'jira_projects.key')
            ->whereNull('jira_projects.key')
            ->count();
            
        $validationResults['orphaned_issues'] = $orphanedIssues;

        // Check for sync histories without checkpoints
        $syncHistoriesWithoutCheckpoints = DB::table('jira_sync_histories')
            ->leftJoin('jira_sync_checkpoints', 'jira_sync_histories.id', '=', 'jira_sync_checkpoints.sync_history_id')
            ->where('jira_sync_histories.status', 'completed')
            ->whereNull('jira_sync_checkpoints.id')
            ->count();
            
        $validationResults['sync_histories_without_checkpoints'] = $syncHistoriesWithoutCheckpoints;
        
        // Calculate overall integrity score
        $totalIssues = array_sum($validationResults);
        $validationResults['integrity_score'] = $totalIssues === 0 ? 100 : max(0, 100 - ($totalIssues * 5));
        
        Log::info('Data integrity validation completed', [
            'task_id' => $this->taskId,
            'validation_results' => $validationResults,
            'integrity_score' => $validationResults['integrity_score']
        ]);

        // Log warnings for significant issues
        if ($totalIssues > 0) {
            Log::warning('Data integrity issues detected', [
                'task_id' => $this->taskId,
                'total_issues' => $totalIssues,
                'details' => $validationResults
            ]);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Background JIRA task failed permanently', [
            'task_id' => $this->taskId,
            'task_type' => $this->taskType,
            'final_error' => $exception->getMessage(),
            'attempts_made' => $this->attempts(),
            'task_options' => $this->taskOptions
        ]);
    }

    /**
     * Calculate delay between retry attempts.
     */
    public function backoff(): array
    {
        return [60, 300]; // 1min, 5min delays for background tasks
    }

    /**
     * Get the tags for job monitoring.
     */
    public function tags(): array
    {
        return [
            'jira-background',
            'maintenance',
            'task:' . $this->taskType,
            'task_id:' . $this->taskId,
            'prd-background'
        ];
    }
}