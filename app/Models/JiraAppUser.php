<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraAppUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_account_id', // JIRA's account ID for the user
        'display_name',
        'email_address', // Optional, if available and needed
    ];

    /**
     * Get the worklogs authored by the user.
     */
    public function worklogs()
    {
        return $this->hasMany(JiraWorklog::class, 'jira_app_user_id');
    }

    /**
     * Get the issues assigned to the user.
     */
    public function assignedIssues()
    {
        return $this->hasMany(JiraIssue::class, 'assignee_jira_app_user_id');
    }
}
