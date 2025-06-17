<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JIRA Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for JIRA synchronization limits and performance
    | tuning. These settings help prevent incomplete syncs and optimize
    | performance for large projects.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Limits
    |--------------------------------------------------------------------------
    |
    | These settings control the maximum number of batches processed during
    | sync operations to prevent infinite loops while allowing large projects
    | to sync completely.
    |
    */

    // Maximum batches for JiraApiService (legacy)
    'max_batches' => env('JIRA_MAX_BATCHES', 10000),

    // Maximum batches for JiraApiServiceV3 (optimized)
    'max_batches_v3' => env('JIRA_MAX_BATCHES_V3', 5000),

    // Issues processed per batch in EnhancedJiraImportService
    'issue_batch_size' => env('JIRA_ISSUE_BATCH_SIZE', 25),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Controls the rate at which requests are sent to JIRA API to prevent
    | rate limiting while maintaining good performance.
    |
    */

    // Requests per second limit
    'requests_per_second' => env('JIRA_REQUESTS_PER_SECOND', 10),

    // Maximum concurrent requests
    'max_concurrent_requests' => env('JIRA_MAX_CONCURRENT_REQUESTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Sync Validation
    |--------------------------------------------------------------------------
    |
    | Settings for data validation and completeness checks after sync
    | operations complete.
    |
    */

    // Enable post-sync validation
    'enable_validation' => env('JIRA_ENABLE_VALIDATION', true),

    // Maximum acceptable data discrepancy percentage
    'max_discrepancy_percent' => env('JIRA_MAX_DISCREPANCY_PERCENT', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | Settings to optimize sync performance for large datasets.
    |
    */

    // Enable dynamic batch sizing based on performance
    'dynamic_batch_sizing' => env('JIRA_DYNAMIC_BATCH_SIZING', false),

    // Memory limit for large syncs (MB)
    'memory_limit_mb' => env('JIRA_MEMORY_LIMIT_MB', 512),

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | Configuration for error handling during sync operations.
    |
    */

    // Stop sync on critical errors
    'stop_on_critical_errors' => env('JIRA_STOP_ON_CRITICAL_ERRORS', true),

    // Maximum number of individual item failures before stopping sync
    'max_item_failures' => env('JIRA_MAX_ITEM_FAILURES', 100),

    /*
    |--------------------------------------------------------------------------
    | Worklog Sync Scheduling
    |--------------------------------------------------------------------------
    |
    | Configuration for automated worklog synchronization scheduling.
    |
    */

    // Enable frequent worklog sync during business hours (every 3 hours on weekdays)
    'enable_frequent_worklog_sync' => env('JIRA_ENABLE_FREQUENT_WORKLOG_SYNC', false),

    // Default timeframe for automated worklog sync (hours)
    'auto_worklog_sync_hours' => env('JIRA_AUTO_WORKLOG_SYNC_HOURS', 24),

    // Enable worklog sync notifications
    'worklog_sync_notifications' => env('JIRA_WORKLOG_SYNC_NOTIFICATIONS', false),

];