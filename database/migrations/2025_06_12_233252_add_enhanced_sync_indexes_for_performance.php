<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Indexes for jira_worklogs table to optimize enhanced sync queries
        Schema::table('jira_worklogs', function (Blueprint $table) {
            // Index for resource type filtering
            $table->index('resource_type', 'idx_jira_worklogs_resource_type');
            
            // Index for date range queries (incremental sync)
            $table->index('started_at', 'idx_jira_worklogs_started_at');
            $table->index('updated_at', 'idx_jira_worklogs_updated_at');
            
            // Composite index for resource type and date filtering
            $table->index(['resource_type', 'started_at'], 'idx_jira_worklogs_resource_date');
            
            // Index for issue-based queries
            $table->index('jira_issue_id', 'idx_jira_worklogs_issue_id');
            
            // Index for user-based queries
            $table->index('jira_app_user_id', 'idx_jira_worklogs_user_id');
            
            // Composite index for project-based reporting (via issue relationship)
            $table->index(['jira_issue_id', 'resource_type'], 'idx_jira_worklogs_issue_resource');
        });

        // Indexes for jira_issues table to optimize issue fetching
        Schema::table('jira_issues', function (Blueprint $table) {
            // Index for project-based queries
            $table->index('jira_project_id', 'idx_jira_issues_project_id');
            
            // Index for updated time queries (incremental sync)
            $table->index('updated_at', 'idx_jira_issues_updated_at');
            
            // Composite index for project and update time
            $table->index(['jira_project_id', 'updated_at'], 'idx_jira_issues_project_updated');
            
            // Index for issue key lookups
            $table->index('issue_key', 'idx_jira_issues_key');
            
            // Index for status filtering
            $table->index('status', 'idx_jira_issues_status');
        });

        // Indexes for jira_projects table
        Schema::table('jira_projects', function (Blueprint $table) {
            // Index for project key lookups (already exists as unique, but adding for consistency)
            if (!Schema::hasIndex('jira_projects', 'idx_jira_projects_key')) {
                $table->index('project_key', 'idx_jira_projects_key');
            }
            
            // Index for JIRA ID lookups
            $table->index('jira_id', 'idx_jira_projects_jira_id');
        });

        // Indexes for jira_app_users table
        Schema::table('jira_app_users', function (Blueprint $table) {
            // Index for account ID lookups (already exists as unique, but adding for consistency)
            if (!Schema::hasIndex('jira_app_users', 'idx_jira_app_users_account_id')) {
                $table->index('jira_account_id', 'idx_jira_app_users_account_id');
            }
            
            // Index for display name searches (resource type classification)
            $table->index('display_name', 'idx_jira_app_users_display_name');
            
            // Index for email searches (resource type classification)
            $table->index('email_address', 'idx_jira_app_users_email');
        });

        // Indexes for jira_sync_histories table
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            // Index for status filtering
            $table->index('status', 'idx_jira_sync_history_status');
            
            // Index for date range queries
            $table->index('started_at', 'idx_jira_sync_history_started_at');
            $table->index('completed_at', 'idx_jira_sync_history_completed_at');
            
            // Index for user-based filtering
            $table->index('triggered_by', 'idx_jira_sync_history_user');
            
            // Index for sync type filtering
            $table->index('sync_type', 'idx_jira_sync_history_type');
            
            // Composite index for status and date
            $table->index(['status', 'started_at'], 'idx_jira_sync_history_status_date');
        });

        // Indexes for jira_sync_checkpoints table
        Schema::table('jira_sync_checkpoints', function (Blueprint $table) {
            // Index for sync history relationship
            $table->index('jira_sync_history_id', 'idx_jira_sync_checkpoints_history_id');
            
            // Index for project key filtering
            $table->index('project_key', 'idx_jira_sync_checkpoints_project_key');
            
            // Index for status filtering
            $table->index('status', 'idx_jira_sync_checkpoints_status');
            
            // Composite index for finding active checkpoints
            $table->index(['status', 'project_key'], 'idx_jira_sync_checkpoints_status_project');
            
            // Index for timestamp queries
            $table->index('created_at', 'idx_jira_sync_checkpoints_created_at');
        });

        // Indexes for jira_project_sync_statuses table
        Schema::table('jira_project_sync_statuses', function (Blueprint $table) {
            // Index for project key lookups (primary query pattern)
            $table->index('project_key', 'idx_jira_project_sync_statuses_key');
            
            // Index for status filtering
            $table->index('last_sync_status', 'idx_jira_project_sync_statuses_status');
            
            // Index for last sync time queries (incremental sync logic)
            $table->index('last_sync_at', 'idx_jira_project_sync_statuses_last_sync');
            
            // Composite index for status and last sync time
            $table->index(['last_sync_status', 'last_sync_at'], 'idx_jira_project_sync_statuses_status_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes for jira_worklogs table
        Schema::table('jira_worklogs', function (Blueprint $table) {
            $table->dropIndex('idx_jira_worklogs_resource_type');
            $table->dropIndex('idx_jira_worklogs_started_at');
            $table->dropIndex('idx_jira_worklogs_updated_at');
            $table->dropIndex('idx_jira_worklogs_resource_date');
            $table->dropIndex('idx_jira_worklogs_issue_id');
            $table->dropIndex('idx_jira_worklogs_user_id');
            $table->dropIndex('idx_jira_worklogs_issue_resource');
        });

        // Drop indexes for jira_issues table
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropIndex('idx_jira_issues_project_id');
            $table->dropIndex('idx_jira_issues_updated_at');
            $table->dropIndex('idx_jira_issues_project_updated');
            $table->dropIndex('idx_jira_issues_key');
            $table->dropIndex('idx_jira_issues_status');
        });

        // Drop indexes for jira_projects table
        Schema::table('jira_projects', function (Blueprint $table) {
            if (Schema::hasIndex('jira_projects', 'idx_jira_projects_key')) {
                $table->dropIndex('idx_jira_projects_key');
            }
            $table->dropIndex('idx_jira_projects_jira_id');
        });

        // Drop indexes for jira_app_users table
        Schema::table('jira_app_users', function (Blueprint $table) {
            if (Schema::hasIndex('jira_app_users', 'idx_jira_app_users_account_id')) {
                $table->dropIndex('idx_jira_app_users_account_id');
            }
            $table->dropIndex('idx_jira_app_users_display_name');
            $table->dropIndex('idx_jira_app_users_email');
        });

        // Drop indexes for jira_sync_histories table
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            $table->dropIndex('idx_jira_sync_history_status');
            $table->dropIndex('idx_jira_sync_history_started_at');
            $table->dropIndex('idx_jira_sync_history_completed_at');
            $table->dropIndex('idx_jira_sync_history_user');
            $table->dropIndex('idx_jira_sync_history_type');
            $table->dropIndex('idx_jira_sync_history_status_date');
        });

        // Drop indexes for jira_sync_checkpoints table
        Schema::table('jira_sync_checkpoints', function (Blueprint $table) {
            $table->dropIndex('idx_jira_sync_checkpoints_history_id');
            $table->dropIndex('idx_jira_sync_checkpoints_project_key');
            $table->dropIndex('idx_jira_sync_checkpoints_status');
            $table->dropIndex('idx_jira_sync_checkpoints_status_project');
            $table->dropIndex('idx_jira_sync_checkpoints_created_at');
        });

        // Drop indexes for jira_project_sync_statuses table
        Schema::table('jira_project_sync_statuses', function (Blueprint $table) {
            $table->dropIndex('idx_jira_project_sync_statuses_key');
            $table->dropIndex('idx_jira_project_sync_statuses_status');
            $table->dropIndex('idx_jira_project_sync_statuses_last_sync');
            $table->dropIndex('idx_jira_project_sync_statuses_status_time');
        });
    }
};
