<?php

namespace App\Services;

use App\Models\Initiative;
use App\Models\JiraIssue;
use App\Models\JiraWorklog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JiraInitiativeService
{
    /**
     * Calculate total hours for an initiative within a date range
     */
    public function calculateHours(Initiative $initiative, ?string $startDate = null, ?string $endDate = null): float
    {
        $query = $this->buildWorklogQuery($initiative, $startDate, $endDate);
        
        $totalSeconds = $query->sum('time_spent_seconds');
        
        return round($totalSeconds / 3600, 2); // Convert to hours
    }

    /**
     * Calculate total cost for an initiative
     */
    public function calculateCost(Initiative $initiative, ?string $startDate = null, ?string $endDate = null): float
    {
        if (!$initiative->hourly_rate) {
            return 0.0;
        }
        
        $hours = $this->calculateHours($initiative, $startDate, $endDate);
        return round($hours * $initiative->hourly_rate, 2);
    }

    /**
     * Get monthly breakdown of hours for an initiative
     */
    public function getMonthlyBreakdown(Initiative $initiative, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = $this->buildWorklogQuery($initiative, $startDate, $endDate);
        
        $monthlyData = $query
            ->selectRaw('
                EXTRACT(YEAR FROM started) as year,
                EXTRACT(MONTH FROM started) as month,
                SUM(time_spent_seconds) as total_seconds,
                COUNT(*) as worklog_count
            ')
            ->groupByRaw('EXTRACT(YEAR FROM started), EXTRACT(MONTH FROM started)')
            ->orderByRaw('year ASC, month ASC')
            ->get();
        
        $breakdown = [];
        foreach ($monthlyData as $row) {
            $monthKey = sprintf('%04d-%02d', $row->year, $row->month);
            $breakdown[$monthKey] = [
                'year' => (int) $row->year,
                'month' => (int) $row->month,
                'hours' => round($row->total_seconds / 3600, 2),
                'worklog_count' => $row->worklog_count,
                'cost' => $initiative->hourly_rate ? round(($row->total_seconds / 3600) * $initiative->hourly_rate, 2) : 0,
            ];
        }
        
        return $breakdown;
    }

    /**
     * Get contributing issues for an initiative
     */
    public function getContributingIssues(Initiative $initiative, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $worklogQuery = $this->buildWorklogQuery($initiative, $startDate, $endDate);
        
        // Get issues with their worklog summaries
        $issueStats = $worklogQuery
            ->selectRaw('
                jira_issue_id,
                SUM(time_spent_seconds) as total_seconds,
                COUNT(*) as worklog_count,
                MIN(started) as first_worklog,
                MAX(started) as last_worklog
            ')
            ->groupBy('jira_issue_id')
            ->get()
            ->keyBy('jira_issue_id');
        
        $issueIds = $issueStats->keys()->toArray();
        
        // Get the actual issue records
        $issues = JiraIssue::whereIn('id', $issueIds)
            ->with(['project'])
            ->get();
        
        // Combine issue data with worklog stats
        return $issues->map(function ($issue) use ($issueStats, $initiative) {
            $stats = $issueStats[$issue->id];
            $hours = round($stats->total_seconds / 3600, 2);
            
            return [
                'issue_key' => $issue->issue_key,
                'summary' => $issue->summary,
                'status' => $issue->status,
                'project_name' => $issue->project->name ?? 'Unknown',
                'labels' => $issue->labels ?? [],
                'epic_key' => $issue->epic_key,
                'hours' => $hours,
                'cost' => $initiative->hourly_rate ? round($hours * $initiative->hourly_rate, 2) : 0,
                'worklog_count' => $stats->worklog_count,
                'first_worklog' => Carbon::parse($stats->first_worklog)->format('Y-m-d'),
                'last_worklog' => Carbon::parse($stats->last_worklog)->format('Y-m-d'),
            ];
        })->sortByDesc('hours')->values();
    }

    /**
     * Get initiative metrics summary
     */
    public function getMetricsSummary(Initiative $initiative, ?string $startDate = null, ?string $endDate = null): array
    {
        $totalHours = $this->calculateHours($initiative, $startDate, $endDate);
        $totalCost = $this->calculateCost($initiative, $startDate, $endDate);
        $monthlyBreakdown = $this->getMonthlyBreakdown($initiative, $startDate, $endDate);
        $contributingIssues = $this->getContributingIssues($initiative, $startDate, $endDate);
        
        return [
            'total_hours' => $totalHours,
            'total_cost' => $totalCost,
            'total_issues' => $contributingIssues->count(),
            'monthly_breakdown' => $monthlyBreakdown,
            'recent_activity' => $this->getRecentActivity($initiative, 30),
        ];
    }

    /**
     * Get recent activity for an initiative
     */
    public function getRecentActivity(Initiative $initiative, int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days)->format('Y-m-d');
        
        $query = $this->buildWorklogQuery($initiative, $startDate);
        
        $recentWorklogs = $query
            ->selectRaw('
                DATE(started) as date,
                SUM(time_spent_seconds) as daily_seconds,
                COUNT(*) as daily_count
            ')
            ->groupByRaw('DATE(started)')
            ->orderByRaw('DATE(started) DESC')
            ->limit(10)
            ->get();
        
        return $recentWorklogs->map(function ($row) use ($initiative) {
            $hours = round($row->daily_seconds / 3600, 2);
            return [
                'date' => $row->date,
                'hours' => $hours,
                'cost' => $initiative->hourly_rate ? round($hours * $initiative->hourly_rate, 2) : 0,
                'worklog_count' => $row->daily_count,
            ];
        })->toArray();
    }

    /**
     * Build worklog query for an initiative based on its filters
     */
    protected function buildWorklogQuery(Initiative $initiative, ?string $startDate = null, ?string $endDate = null)
    {
        $query = JiraWorklog::query()
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id');
        
        // Apply initiative filters
        $projectFilters = $initiative->projectFilters;
        
        if ($projectFilters->isEmpty()) {
            // No filters defined, return empty result
            return $query->whereRaw('1 = 0');
        }
        
        $query->where(function ($q) use ($projectFilters) {
            foreach ($projectFilters as $filter) {
                $q->orWhere(function ($subQ) use ($filter) {
                    // Project filter
                    $subQ->where('jira_issues.jira_project_id', $filter->jira_project_id);
                    
                    // Epic filter if specified
                    if ($filter->epic_key) {
                        $subQ->where('jira_issues.epic_key', $filter->epic_key);
                    }
                    
                    // Labels filter if specified
                    if ($filter->required_labels && !empty($filter->required_labels)) {
                        foreach ($filter->required_labels as $requiredLabel) {
                            // Use JSON contains for PostgreSQL
                            $subQ->whereJsonContains('jira_issues.labels', $requiredLabel);
                        }
                    }
                });
            }
        });
        
        // Apply date filters
        if ($startDate) {
            $query->where('jira_worklogs.started', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('jira_worklogs.started', '<=', $endDate . ' 23:59:59');
        }
        
        // Select worklog fields
        $query->select('jira_worklogs.*');
        
        Log::debug('Initiative worklog query built', [
            'initiative_id' => $initiative->id,
            'filters_count' => $projectFilters->count(),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        
        return $query;
    }

    /**
     * Get all initiatives accessible by a user
     */
    public function getAccessibleInitiatives($user): Collection
    {
        return $user->initiatives()
            ->with(['projectFilters.jiraProject'])
            ->active()
            ->get();
    }

    /**
     * Check if an issue belongs to any initiative
     */
    public function getIssueInitiatives(JiraIssue $issue): Collection
    {
        return Initiative::active()
            ->with('projectFilters')
            ->get()
            ->filter(function ($initiative) use ($issue) {
                return $initiative->projectFilters->contains(function ($filter) use ($issue) {
                    return $filter->matchesIssue($issue);
                });
            });
    }
}