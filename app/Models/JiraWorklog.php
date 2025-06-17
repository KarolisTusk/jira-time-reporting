<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraWorklog extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_issue_id', // Foreign key to jira_issues table
        'jira_app_user_id', // Foreign key to jira_app_users table for the author
        'jira_id', // JIRA's internal ID for the worklog
        'time_spent_seconds',
        'started_at',
        'resource_type', // Enhanced sync: categorize work by type
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    /**
     * Get the issue that the worklog belongs to.
     */
    public function issue()
    {
        return $this->belongsTo(JiraIssue::class, 'jira_issue_id');
    }

    /**
     * Get the author of the worklog.
     */
    public function author()
    {
        return $this->belongsTo(JiraAppUser::class, 'jira_app_user_id');
    }

    /**
     * Alternative alias for author relationship for enhanced sync.
     */
    public function user()
    {
        return $this->belongsTo(JiraAppUser::class, 'jira_app_user_id');
    }

    /**
     * Scope to filter worklogs by date range.
     */
    public function scopeInDateRange($query, $startDate = null, $endDate = null)
    {
        if ($startDate) {
            $query->where('started_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('started_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Scope to get total time spent per project with optional date filtering.
     */
    public function scopeTotalTimePerProject($query, $startDate = null, $endDate = null)
    {
        return $query->select(
            'jira_projects.id as project_id',
            'jira_projects.project_key',
            'jira_projects.name as project_name',
            \DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_time_seconds'),
            \DB::raw('COUNT(jira_worklogs.id) as worklog_count')
        )
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->inDateRange($startDate, $endDate)
            ->groupBy('jira_projects.id', 'jira_projects.project_key', 'jira_projects.name')
            ->orderBy('total_time_seconds', 'desc');
    }

    /**
     * Scope to get total time spent by each user on a specific project.
     */
    public function scopeTotalTimeByUserForProject($query, $projectId = null, $startDate = null, $endDate = null)
    {
        $query = $query->select(
            'jira_app_users.id as user_id',
            'jira_app_users.jira_account_id',
            'jira_app_users.display_name as user_name',
            'jira_app_users.email_address',
            'jira_projects.id as project_id',
            'jira_projects.project_key',
            'jira_projects.name as project_name',
            \DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_time_seconds'),
            \DB::raw('COUNT(jira_worklogs.id) as worklog_count')
        )
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->join('jira_app_users', 'jira_worklogs.jira_app_user_id', '=', 'jira_app_users.id')
            ->inDateRange($startDate, $endDate)
            ->groupBy(
                'jira_app_users.id',
                'jira_app_users.jira_account_id',
                'jira_app_users.display_name',
                'jira_app_users.email_address',
                'jira_projects.id',
                'jira_projects.project_key',
                'jira_projects.name'
            )
            ->orderBy('total_time_seconds', 'desc');

        // Filter by specific project if provided
        if ($projectId) {
            $query->where('jira_projects.id', $projectId);
        }

        return $query;
    }

    /**
     * Scope to get time trend data per project over specified periods.
     */
    public function scopeProjectTimeTrend($query, $period = 'weekly', $projectIds = null, $startDate = null, $endDate = null)
    {
        // Get the database driver to determine which date function to use
        $driver = \DB::connection()->getDriverName();

        // Determine the date format and grouping based on period and database driver
        if ($driver === 'sqlite') {
            $dateFormat = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%W', // Year-Week for SQLite
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%W' // Default to weekly
            };
            $dateFunction = "strftime('{$dateFormat}', jira_worklogs.started_at)";
        } else {
            // MySQL/MariaDB
            $dateFormat = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u', // Year-Week for MySQL
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%u' // Default to weekly
            };
            $dateFunction = "DATE_FORMAT(jira_worklogs.started_at, '{$dateFormat}')";
        }

        $query = $query->select(
            'jira_projects.id as project_id',
            'jira_projects.project_key',
            'jira_projects.name as project_name',
            \DB::raw("{$dateFunction} as period"),
            \DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_time_seconds'),
            \DB::raw('COUNT(jira_worklogs.id) as worklog_count')
        )
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->inDateRange($startDate, $endDate)
            ->groupBy('jira_projects.id', 'jira_projects.project_key', 'jira_projects.name', 'period')
            ->orderBy('period')
            ->orderBy('jira_projects.project_key');

        // Filter by specific projects if provided
        if ($projectIds && is_array($projectIds) && ! empty($projectIds)) {
            $query->whereIn('jira_projects.id', $projectIds);
        } elseif ($projectIds && ! is_array($projectIds)) {
            $query->where('jira_projects.id', $projectIds);
        }

        return $query;
    }

    /**
     * Helper method to format seconds into human-readable time.
     */
    public function getFormattedTimeSpent(): string
    {
        return $this->formatSeconds($this->time_spent_seconds);
    }

    /**
     * Static helper method to format seconds into human-readable format.
     */
    public static function formatSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        } else {
            return sprintf('%ds', $remainingSeconds);
        }
    }

    // Enhanced sync methods

    /**
     * Scope to filter worklogs by resource type.
     */
    public function scopeByResourceType($query, $resourceType)
    {
        return $query->where('resource_type', $resourceType);
    }

    /**
     * Scope to filter worklogs by multiple resource types.
     */
    public function scopeByResourceTypes($query, array $resourceTypes)
    {
        return $query->whereIn('resource_type', $resourceTypes);
    }

    /**
     * Scope to get worklogs updated since a specific timestamp.
     */
    public function scopeUpdatedSince($query, $timestamp)
    {
        return $query->where('updated_at', '>=', $timestamp);
    }

    /**
     * Scope to get worklogs for a specific JIRA project.
     */
    public function scopeForProject($query, $projectKey)
    {
        return $query->whereHas('issue.project', function ($q) use ($projectKey) {
            $q->where('project_key', $projectKey);
        });
    }

    /**
     * Enhanced scope to get total time by resource type per project.
     */
    public function scopeTotalTimeByResourceType($query, $projectId = null, $startDate = null, $endDate = null)
    {
        $query = $query->select(
            'jira_projects.id as project_id',
            'jira_projects.project_key',
            'jira_projects.name as project_name',
            'jira_worklogs.resource_type',
            \DB::raw('SUM(jira_worklogs.time_spent_seconds) as total_time_seconds'),
            \DB::raw('COUNT(jira_worklogs.id) as worklog_count'),
            \DB::raw('COUNT(DISTINCT jira_worklogs.jira_app_user_id) as unique_users')
        )
            ->join('jira_issues', 'jira_worklogs.jira_issue_id', '=', 'jira_issues.id')
            ->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
            ->inDateRange($startDate, $endDate)
            ->groupBy('jira_projects.id', 'jira_projects.project_key', 'jira_projects.name', 'jira_worklogs.resource_type')
            ->orderBy('jira_projects.project_key')
            ->orderBy('total_time_seconds', 'desc');

        if ($projectId) {
            $query->where('jira_projects.id', $projectId);
        }

        return $query;
    }

    /**
     * Get the worklog time in hours.
     */
    public function getTimeSpentHoursAttribute(): float
    {
        return round($this->time_spent_seconds / 3600, 2);
    }

    /**
     * Check if this worklog was created during business hours.
     */
    public function getIsBusinessHoursAttribute(): bool
    {
        $hour = $this->started_at->hour;
        return $hour >= 9 && $hour <= 17;
    }

    /**
     * Get the worklog date in a standardized format.
     */
    public function getWorklogDateAttribute(): string
    {
        return $this->started_at->format('Y-m-d');
    }

    /**
     * Get available resource types.
     */
    public static function getResourceTypes(): array
    {
        return [
            'frontend' => 'Frontend Development',
            'backend' => 'Backend Development',
            'qa' => 'Quality Assurance',
            'devops' => 'DevOps & Infrastructure',
            'management' => 'Project Management',
            'architect' => 'Technical Architecture',
            'content management' => 'Content Management',
            'development' => 'General Development',
        ];
    }

    /**
     * Get resource type statistics for reporting.
     */
    public static function getResourceTypeStats($projectId = null, $startDate = null, $endDate = null): array
    {
        $query = static::select('resource_type')
            ->selectRaw('COUNT(*) as worklog_count')
            ->selectRaw('SUM(time_spent_seconds) as total_seconds')
            ->selectRaw('COUNT(DISTINCT jira_app_user_id) as unique_users');

        if ($projectId) {
            $query->whereHas('issue', function ($q) use ($projectId) {
                $q->where('jira_project_id', $projectId);
            });
        }

        if ($startDate) {
            $query->where('started_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('started_at', '<=', $endDate);
        }

        return $query->groupBy('resource_type')
            ->orderBy('total_seconds', 'desc')
            ->get()
            ->map(function ($stat) {
                return [
                    'resource_type' => $stat->resource_type,
                    'display_name' => static::getResourceTypes()[$stat->resource_type] ?? $stat->resource_type,
                    'worklog_count' => $stat->worklog_count,
                    'total_hours' => round($stat->total_seconds / 3600, 2),
                    'unique_users' => $stat->unique_users,
                ];
            })->toArray();
    }
}
