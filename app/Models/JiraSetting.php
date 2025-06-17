<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JiraSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'api_token',
        'project_keys',
        'jira_host', // Added to store the JIRA instance host
        'jira_email', // Email for JIRA authentication
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $casts = [
        'project_keys' => 'array',
        'api_token' => 'encrypted', // Laravel's built-in cast for encryption
    ];
}
