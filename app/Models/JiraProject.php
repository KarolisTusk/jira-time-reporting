<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JiraProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_id', // JIRA's internal ID for the project
        'project_key', // e.g., PROJ
        'name',
    ];

    /**
     * Get the issues for the JIRA project.
     */
    public function issues(): HasMany
    {
        return $this->hasMany(JiraIssue::class);
    }

    /**
     * Get initiatives this project is associated with
     */
    public function initiatives(): BelongsToMany
    {
        return $this->belongsToMany(Initiative::class, 'initiative_project_filters', 'jira_project_id')
            ->withPivot(['required_labels', 'epic_key'])
            ->withTimestamps();
    }

    /**
     * Get initiative project filters for this project
     */
    public function initiativeFilters(): HasMany
    {
        return $this->hasMany(InitiativeProjectFilter::class, 'jira_project_id');
    }
}
