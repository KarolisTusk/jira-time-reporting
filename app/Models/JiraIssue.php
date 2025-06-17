<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_project_id', // Foreign key to jira_projects table
        'jira_id', // JIRA's internal ID for the issue
        'issue_key', // e.g., PROJ-123
        'summary',
        'status',
        'labels', // JSON array of labels
        'epic_key', // Epic key (e.g., PROJ-456)
        'assignee_jira_app_user_id', // Foreign key to jira_app_users table for the assignee
        'original_estimate_seconds', // Store estimate in seconds
    ];

    protected $casts = [
        'labels' => 'array',
    ];

    /**
     * Get the project that owns the issue.
     */
    public function project()
    {
        return $this->belongsTo(JiraProject::class, 'jira_project_id');
    }

    /**
     * Get the worklogs for the issue.
     */
    public function worklogs()
    {
        return $this->hasMany(JiraWorklog::class);
    }

    /**
     * Get the assignee of the issue.
     */
    public function assignee()
    {
        return $this->belongsTo(JiraAppUser::class, 'assignee_jira_app_user_id');
    }
}
