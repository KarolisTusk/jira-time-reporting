<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeProjectFilter extends Model
{
    use HasFactory;

    protected $fillable = [
        'initiative_id',
        'jira_project_id',
        'required_labels',
        'epic_key',
    ];

    protected $casts = [
        'required_labels' => 'array',
    ];

    /**
     * Get the initiative this filter belongs to
     */
    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    /**
     * Get the JIRA project this filter belongs to
     */
    public function jiraProject(): BelongsTo
    {
        return $this->belongsTo(JiraProject::class, 'jira_project_id');
    }

    /**
     * Check if a JIRA issue matches this filter
     */
    public function matchesIssue(JiraIssue $issue): bool
    {
        // Check if issue belongs to the correct project
        if ($issue->jira_project_id !== $this->jira_project_id) {
            return false;
        }

        // Check epic key if specified
        if ($this->epic_key && $issue->epic_key !== $this->epic_key) {
            return false;
        }

        // Check required labels if specified
        if ($this->required_labels && !empty($this->required_labels)) {
            $issueLabels = $issue->labels ?? [];
            
            foreach ($this->required_labels as $requiredLabel) {
                if (!in_array($requiredLabel, $issueLabels)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get a human-readable description of this filter
     */
    public function getDescriptionAttribute(): string
    {
        $parts = [];
        
        if ($this->jiraProject) {
            $parts[] = "Project: {$this->jiraProject->name}";
        }
        
        if ($this->required_labels && !empty($this->required_labels)) {
            $labels = implode(', ', $this->required_labels);
            $parts[] = "Labels: {$labels}";
        }
        
        if ($this->epic_key) {
            $parts[] = "Epic: {$this->epic_key}";
        }
        
        return implode(' | ', $parts);
    }
}
