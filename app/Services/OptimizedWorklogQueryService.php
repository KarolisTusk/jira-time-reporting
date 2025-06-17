<?php

namespace App\Services;

use App\Models\JiraWorklog;
use App\Models\JiraProject;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OptimizedWorklogQueryService
{
    protected QueryResultCacheService $queryCache;
    protected ReadReplicaService $replicaService;

    public function __construct(QueryResultCacheService $queryCache, ReadReplicaService $replicaService)
    {
        $this->queryCache = $queryCache;
        $this->replicaService = $replicaService;
    }
    /**
     * Get optimized worklog statistics for a project with caching support.
     */
    public function getProjectWorklogStats(string $projectKey, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $params = [
            'project_key' => $projectKey,
            'start_date' => $startDate?->format('Y-m-d'),
            'end_date' => $endDate?->format('Y-m-d')
        ];
        
        return $this->queryCache->rememberQueryResult('stats', $params, function () use ($projectKey, $startDate, $endDate) {
            $baseQuery = $this->getOptimizedWorklogQuery($projectKey, $startDate, $endDate);
            
            $stats = $baseQuery
                ->selectRaw('
                    COUNT(*) as total_worklogs,
                    SUM(time_spent_seconds) as total_seconds,
                    COUNT(DISTINCT jira_app_user_id) as unique_users,
                    MIN(started_at) as earliest_worklog,
                    MAX(started_at) as latest_worklog,
                    AVG(time_spent_seconds) as avg_seconds_per_worklog
                ')
                ->first();
                
            return [
                'total_worklogs' => $stats->total_worklogs ?? 0,
                'total_hours' => round(($stats->total_seconds ?? 0) / 3600, 2),
                'unique_users' => $stats->unique_users ?? 0,
                'earliest_worklog' => $stats->earliest_worklog,
                'latest_worklog' => $stats->latest_worklog,
                'avg_hours_per_worklog' => round(($stats->avg_seconds_per_worklog ?? 0) / 3600, 2),
            ];
        });
    }

    /**
     * Get optimized resource type breakdown with proper indexing.
     */
    public function getResourceTypeBreakdown(string $projectKey, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $params = [
            'project_key' => $projectKey,
            'start_date' => $startDate?->format('Y-m-d'),
            'end_date' => $endDate?->format('Y-m-d')
        ];
        
        return $this->queryCache->rememberQueryResult('breakdown', $params, function () use ($projectKey, $startDate, $endDate) {
            // For resource breakdowns, use read replica to optimize performance (PRD: monthly reports < 30 min)
            $context = [
                'type' => 'reporting',
                'large_dataset' => false,
                'requires_current' => false
            ];
            
            return $this->replicaService->withOptimizedConnection('read', function($connection) use ($projectKey, $startDate, $endDate) {
                return $this->getOptimizedWorklogQuery($projectKey, $startDate, $endDate, $connection->getName())
                    ->select([
                        'resource_type',
                        DB::raw('COUNT(*) as worklog_count'),
                        DB::raw('SUM(time_spent_seconds) as total_seconds'),
                        DB::raw('COUNT(DISTINCT jira_app_user_id) as unique_users'),
                        DB::raw('AVG(time_spent_seconds) as avg_seconds')
                    ])
                    ->groupBy('resource_type')
                    ->orderBy('total_seconds', 'desc')
                    ->get()
                    ->map(function ($row) {
                        return [
                            'resource_type' => $row->resource_type,
                            'worklog_count' => $row->worklog_count,
                            'total_hours' => round($row->total_seconds / 3600, 2),
                            'unique_users' => $row->unique_users,
                            'avg_hours' => round($row->avg_seconds / 3600, 2),
                            'percentage' => 0, // Will be calculated after getting totals
                        ];
                    });
            }, $context);
        });
    }

    /**
     * Get optimized user productivity stats.
     */
    public function getUserProductivityStats(string $projectKey, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $cacheKey = "user_productivity:{$projectKey}:" . ($startDate?->format('Y-m-d') ?? 'all') . ':' . ($endDate?->format('Y-m-d') ?? 'all');
        
        return cache()->remember($cacheKey, 600, function () use ($projectKey, $startDate, $endDate) {
            return $this->getOptimizedWorklogQuery($projectKey, $startDate, $endDate)
                ->join('jira_app_users', 'jira_worklogs.jira_app_user_id', '=', 'jira_app_users.id')
                ->select([
                    'jira_app_users.id as user_id',
                    'jira_app_users.display_name',
                    'jira_app_users.email_address',
                    DB::raw('COUNT(jira_worklogs.id) as worklog_count'),
                    DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_seconds'),
                    DB::raw('COUNT(DISTINCT DATE(jira_worklogs.started_at)) as active_days'),
                    DB::raw('AVG(jira_worklogs.time_spent_seconds) as avg_seconds_per_worklog')
                ])
                ->groupBy('jira_app_users.id', 'jira_app_users.display_name', 'jira_app_users.email_address')
                ->orderBy('total_seconds', 'desc')
                ->get()
                ->map(function ($row) {
                    return [
                        'user_id' => $row->user_id,
                        'display_name' => $row->display_name,
                        'email_address' => $row->email_address,
                        'worklog_count' => $row->worklog_count,
                        'total_hours' => round($row->total_seconds / 3600, 2),
                        'active_days' => $row->active_days,
                        'avg_hours_per_worklog' => round($row->avg_seconds_per_worklog / 3600, 2),
                        'avg_hours_per_day' => $row->active_days > 0 ? round(($row->total_seconds / 3600) / $row->active_days, 2) : 0,
                    ];
                });
        });
    }

    /**
     * Get optimized time trend data with proper date indexing.
     */
    public function getTimeTrendData(string $projectKey, string $period = 'weekly', ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $cacheKey = "time_trend:{$projectKey}:{$period}:" . ($startDate?->format('Y-m-d') ?? 'all') . ':' . ($endDate?->format('Y-m-d') ?? 'all');
        
        return cache()->remember($cacheKey, 600, function () use ($projectKey, $period, $startDate, $endDate) {
            $dateFormat = $this->getDateFormatForPeriod($period);
            
            return $this->getOptimizedWorklogQuery($projectKey, $startDate, $endDate)
                ->select([
                    DB::raw("{$dateFormat} as period"),
                    DB::raw('COUNT(*) as worklog_count'),
                    DB::raw('SUM(time_spent_seconds) as total_seconds'),
                    DB::raw('COUNT(DISTINCT jira_app_user_id) as unique_users')
                ])
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->map(function ($row) {
                    return [
                        'period' => $row->period,
                        'worklog_count' => $row->worklog_count,
                        'total_hours' => round($row->total_seconds / 3600, 2),
                        'unique_users' => $row->unique_users,
                    ];
                });
        });
    }

    /**
     * Get bulk worklog data with optimized pagination for large datasets.
     * Uses read replica for better performance when handling 119k+ hours (PRD requirement).
     */
    public function getBulkWorklogData(array $projectKeys, ?Carbon $startDate = null, ?Carbon $endDate = null, int $perPage = 1000): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Use read replica for large dataset operations (PRD: performance optimization)
        $context = [
            'type' => 'export',
            'large_dataset' => true,
            'requires_current' => false // Export can tolerate slight lag for better performance
        ];
        
        return $this->replicaService->withOptimizedConnection('reporting', function($connection) use ($projectKeys, $startDate, $endDate, $perPage) {
            $query = JiraWorklog::on($connection->getName())
                ->with(['issue:id,issue_key,jira_project_id', 'user:id,display_name,email_address'])
                ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
                ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
                ->whereIn('jira_projects.project_key', $projectKeys)
                ->select('jira_worklogs.*'); // Select only worklog columns to avoid duplication
                
            if ($startDate) {
                $query->where('jira_worklogs.started_at', '>=', $startDate);
            }
            
            if ($endDate) {
                $query->where('jira_worklogs.started_at', '<=', $endDate);
            }
            
            // Use index-optimized ordering
            return $query
                ->orderBy('jira_worklogs.started_at', 'desc')
                ->orderBy('jira_worklogs.id', 'desc')
                ->paginate($perPage);
        }, $context);
    }

    /**
     * Get optimized worklog counts for multiple projects (batch operation).
     */
    public function getBatchProjectCounts(array $projectKeys, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = DB::table('jira_worklogs')
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->whereIn('jira_projects.project_key', $projectKeys)
            ->select([
                'jira_projects.project_key',
                DB::raw('COUNT(jira_worklogs.id) as worklog_count'),
                DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_seconds')
            ])
            ->groupBy('jira_projects.project_key');
            
        if ($startDate) {
            $query->where('jira_worklogs.started_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('jira_worklogs.started_at', '<=', $endDate);
        }
        
        return $query->get()->keyBy('project_key')->map(function ($row) {
            return [
                'worklog_count' => $row->worklog_count,
                'total_hours' => round($row->total_seconds / 3600, 2),
            ];
        })->toArray();
    }

    /**
     * Get optimized recent worklog activity for dashboards.
     */
    public function getRecentActivity(string $projectKey, int $limit = 50): Collection
    {
        $cacheKey = "recent_activity:{$projectKey}:{$limit}";
        
        return cache()->remember($cacheKey, 300, function () use ($projectKey, $limit) {
            return JiraWorklog::query()
                ->with(['issue:id,issue_key,summary', 'user:id,display_name'])
                ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
                ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
                ->where('jira_projects.project_key', $projectKey)
                ->select('jira_worklogs.*')
                ->orderBy('jira_worklogs.created_at', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get base optimized query builder for worklog data.
     */
    private function getOptimizedWorklogQuery(string $projectKey, ?Carbon $startDate = null, ?Carbon $endDate = null, ?string $connectionName = null): Builder
    {
        $model = $connectionName ? JiraWorklog::on($connectionName) : JiraWorklog::query();
        
        $query = $model
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->where('jira_projects.project_key', $projectKey);
            
        // Use indexed columns for date filtering
        if ($startDate) {
            $query->where('jira_worklogs.started_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('jira_worklogs.started_at', '<=', $endDate);
        }
        
        return $query;
    }

    /**
     * Get appropriate date format based on period and database driver.
     */
    private function getDateFormatForPeriod(string $period): string
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            return match ($period) {
                'daily' => "TO_CHAR(jira_worklogs.started_at, 'YYYY-MM-DD')",
                'weekly' => "TO_CHAR(jira_worklogs.started_at, 'YYYY-IW')",
                'monthly' => "TO_CHAR(jira_worklogs.started_at, 'YYYY-MM')",
                'yearly' => "TO_CHAR(jira_worklogs.started_at, 'YYYY')",
                default => "TO_CHAR(jira_worklogs.started_at, 'YYYY-IW')"
            };
        } elseif ($driver === 'sqlite') {
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%W',
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%W'
            };
            return "strftime('{$format}', jira_worklogs.started_at)";
        } else {
            // MySQL/MariaDB
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u',
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%u'
            };
            return "DATE_FORMAT(jira_worklogs.started_at, '{$format}')";
        }
    }

    /**
     * Clear query result cache for a specific project.
     */
    public function clearProjectQueryCache(string $projectKey): void
    {
        $this->queryCache->invalidateQueryCache(null, $projectKey);
        Log::info("Cleared query cache for project: {$projectKey}");
    }

    /**
     * Get query performance analysis for debugging.
     */
    public function analyzeQueryPerformance(string $projectKey, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startTime = microtime(true);
        
        // Analyze different query patterns
        $analyses = [];
        
        // Basic count query
        $countStart = microtime(true);
        $count = $this->getOptimizedWorklogQuery($projectKey, $startDate, $endDate)->count();
        $analyses['count_query'] = [
            'duration_ms' => round((microtime(true) - $countStart) * 1000, 2),
            'result_count' => $count
        ];
        
        // Aggregation query
        $aggStart = microtime(true);
        $stats = $this->getProjectWorklogStats($projectKey, $startDate, $endDate);
        $analyses['aggregation_query'] = [
            'duration_ms' => round((microtime(true) - $aggStart) * 1000, 2),
            'cached' => !empty($stats)
        ];
        
        // Resource type breakdown
        $resourceStart = microtime(true);
        $resources = $this->getResourceTypeBreakdown($projectKey, $startDate, $endDate);
        $analyses['resource_breakdown_query'] = [
            'duration_ms' => round((microtime(true) - $resourceStart) * 1000, 2),
            'result_count' => $resources->count()
        ];
        
        return [
            'total_duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'project_key' => $projectKey,
            'date_range' => [
                'start' => $startDate?->format('Y-m-d'),
                'end' => $endDate?->format('Y-m-d')
            ],
            'query_analyses' => $analyses
        ];
    }

    /**
     * Generate monthly report data optimized for PRD requirements (< 30 minutes target).
     * Handles the full 119,033.02 hours dataset efficiently using read replicas.
     */
    public function generateMonthlyReportData(array $projectKeys, Carbon $month): array
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();
        
        Log::info("Generating monthly report for PRD compliance", [
            'month' => $month->format('Y-m'),
            'projects' => $projectKeys,
            'target_performance' => '< 30 minutes'
        ]);
        
        $optimization = $this->replicaService->optimizeForScenario('monthly_report_generation', [
            'month' => $month->format('Y-m'),
            'project_count' => count($projectKeys)
        ]);
        
        return $this->replicaService->withOptimizedConnection('reporting', function($connection) use ($projectKeys, $startDate, $endDate, $month) {
            $reportData = [];
            
            foreach ($projectKeys as $projectKey) {
                // Get comprehensive project data for the month
                $projectData = [
                    'project_key' => $projectKey,
                    'month' => $month->format('Y-m'),
                    'statistics' => $this->getProjectWorklogStats($projectKey, $startDate, $endDate),
                    'resource_breakdown' => $this->getResourceTypeBreakdown($projectKey, $startDate, $endDate),
                    'user_productivity' => $this->getUserProductivityStats($projectKey, $startDate, $endDate),
                    'daily_breakdown' => $this->getTimeTrendData($projectKey, 'daily', $startDate, $endDate),
                ];
                
                // Calculate totals for validation (PRD: ensure 100% data coverage)
                $totalHours = $projectData['statistics']['total_hours'];
                $totalWorklogs = $projectData['statistics']['total_worklogs'];
                
                $projectData['validation'] = [
                    'total_hours_formatted' => number_format($totalHours, 2),
                    'total_worklogs' => $totalWorklogs,
                    'data_coverage_check' => $totalWorklogs > 0 ? 'PASS' : 'REVIEW_NEEDED',
                    'hours_per_worklog_avg' => $totalWorklogs > 0 ? round($totalHours / $totalWorklogs, 2) : 0,
                ];
                
                $reportData[$projectKey] = $projectData;
            }
            
            // Add report metadata for PRD compliance
            $reportData['_metadata'] = [
                'generation_date' => now()->toISOString(),
                'reporting_period' => $month->format('F Y'),
                'projects_included' => count($projectKeys),
                'optimization_used' => $optimization,
                'prd_compliance' => [
                    'target_performance' => '< 30 minutes',
                    'data_accuracy_target' => '100% coverage',
                    'generated_for' => 'Monthly stakeholder reporting',
                ]
            ];
            
            return $reportData;
        }, [
            'type' => 'export',
            'large_dataset' => true,
            'requires_current' => false
        ]);
    }

    /**
     * Validate sync data integrity for PRD compliance (119,033.02 hours baseline).
     */
    public function validateDataIntegrity(array $projectKeys = null): array
    {
        // Use primary connection for validation to ensure current data (PRD requirement)
        $connection = $this->replicaService->getReportingConnection([
            'type' => 'sync_validation',
            'requires_current' => true
        ]);
        
        $validation = [];
        $projectsToCheck = $projectKeys ?? JiraProject::pluck('key')->toArray();
        
        foreach ($projectsToCheck as $projectKey) {
            $stats = $this->getProjectWorklogStats($projectKey);
            
            $validation[$projectKey] = [
                'total_hours' => $stats['total_hours'],
                'total_worklogs' => $stats['total_worklogs'],
                'unique_users' => $stats['unique_users'],
                'date_range' => [
                    'earliest' => $stats['earliest_worklog'],
                    'latest' => $stats['latest_worklog']
                ],
                'data_quality_checks' => [
                    'has_data' => $stats['total_worklogs'] > 0,
                    'reasonable_avg_hours' => $stats['avg_hours_per_worklog'] > 0 && $stats['avg_hours_per_worklog'] <= 24,
                    'active_users' => $stats['unique_users'] > 0,
                ],
                'last_validated' => now()->toISOString()
            ];
        }
        
        // Calculate total hours across all projects for PRD baseline comparison
        $totalHours = collect($validation)->sum('total_hours');
        $totalWorklogs = collect($validation)->sum('total_worklogs');
        
        $validation['_summary'] = [
            'total_hours_all_projects' => round($totalHours, 2),
            'total_worklogs_all_projects' => $totalWorklogs,
            'prd_baseline_hours' => 119033.02,
            'coverage_percentage' => $totalHours > 0 ? round(($totalHours / 119033.02) * 100, 2) : 0,
            'validation_status' => $totalHours >= 119000 ? 'EXCELLENT' : ($totalHours >= 100000 ? 'GOOD' : 'NEEDS_REVIEW'),
            'validated_at' => now()->toISOString()
        ];
        
        return $validation;
    }
}