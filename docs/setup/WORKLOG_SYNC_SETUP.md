# Incremental Worklog Sync Setup Guide

## Overview

This guide covers the setup and configuration of the Incremental Worklog Sync feature introduced in Version 7.0, which enables fast daily maintenance synchronization of JIRA worklogs with comprehensive validation and automated scheduling.

## Prerequisites

Before setting up incremental worklog sync, ensure you have:

- ✅ JIRA settings configured (`/settings/jira`)
- ✅ At least one full JIRA sync completed successfully
- ✅ PostgreSQL database with proper indexes
- ✅ Laravel Horizon running for queue processing
- ✅ Redis available for queue management

## Quick Setup

### 1. Database Migration

Ensure you have the latest database schema:

```bash
# Run migrations for worklog sync status table
php artisan migrate

# Verify the table was created
php artisan tinker --execute="Schema::hasTable('jira_worklog_sync_statuses')"
```

### 2. Queue Configuration

The worklog sync uses a dedicated queue. Ensure Horizon is configured:

```bash
# Check Horizon configuration
php artisan horizon:status

# Start Horizon if not running
php artisan horizon

# Verify worklog sync queue is configured
php artisan queue:monitor jira-worklog-sync
```

### 3. Test Worklog Sync

Run a test sync to verify everything is working:

```bash
# Test sync with dry run
php artisan jira:sync-worklogs --dry-run

# Run actual sync for last 24 hours
php artisan jira:sync-worklogs --hours=24

# Check sync status
php artisan jira:sync-worklogs --status
```

## Detailed Configuration

### JIRA Configuration (`config/jira.php`)

Configure worklog sync settings:

```php
<?php

return [
    // ... existing configuration ...

    /*
    |--------------------------------------------------------------------------
    | Worklog Sync Scheduling
    |--------------------------------------------------------------------------
    */

    // Enable frequent worklog sync during business hours (12 PM & 3 PM on weekdays)
    'enable_frequent_worklog_sync' => env('JIRA_ENABLE_FREQUENT_WORKLOG_SYNC', false),

    // Default timeframe for automated worklog sync (hours)
    'auto_worklog_sync_hours' => env('JIRA_AUTO_WORKLOG_SYNC_HOURS', 24),

    // Enable worklog sync notifications
    'worklog_sync_notifications' => env('JIRA_WORKLOG_SYNC_NOTIFICATIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    */

    // Enable post-sync validation
    'enable_validation' => env('JIRA_ENABLE_VALIDATION', true),

    // Maximum acceptable data discrepancy percentage
    'max_discrepancy_percent' => env('JIRA_MAX_DISCREPANCY_PERCENT', 5.0),
];
```

### Environment Variables

Add these to your `.env` file:

```env
# Worklog Sync Configuration
JIRA_ENABLE_FREQUENT_WORKLOG_SYNC=false
JIRA_AUTO_WORKLOG_SYNC_HOURS=24
JIRA_WORKLOG_SYNC_NOTIFICATIONS=false

# Validation Configuration
JIRA_ENABLE_VALIDATION=true
JIRA_MAX_DISCREPANCY_PERCENT=5.0

# Enhanced Batch Processing (if not already set)
JIRA_MAX_BATCHES=10000
JIRA_MAX_BATCHES_V3=5000
```

### Horizon Queue Configuration

The worklog sync queue should already be configured in `config/horizon.php`. Verify the configuration:

```php
'defaults' => [
    // ... existing queues ...

    // Dedicated queue for worklog incremental sync
    'jira-worklog-sync' => [
        'connection' => 'redis',
        'queue' => ['jira-worklog-sync'],
        'balance' => 'auto',
        'autoScalingStrategy' => 'time',
        'maxProcesses' => 2,
        'maxTime' => 1800, // 30 minutes
        'maxJobs' => 100,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 1800,
        'nice' => 3,
    ],
],

'environments' => [
    'production' => [
        // ... existing environment configs ...
        
        'jira-worklog-sync' => [
            'maxProcesses' => 3,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
    ],

    'local' => [
        // ... existing environment configs ...
        
        'jira-worklog-sync' => [
            'maxProcesses' => 1,
        ],
    ],
],
```

## Automated Scheduling Setup

### Laravel Task Scheduler

The worklog sync is automatically scheduled in `routes/console.php`. Verify the schedule configuration:

```php
// Morning sync - catches overnight worklogs
Schedule::command('jira:sync-worklogs --hours=24 --async')
    ->dailyAt('09:00')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning('Scheduled morning worklog sync failed');
    });

// Evening sync - catches daily worklogs
Schedule::command('jira:sync-worklogs --hours=8 --async')
    ->dailyAt('17:00')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning('Scheduled evening worklog sync failed');
    });

// Optional: Business hours sync (if enabled)
Schedule::command('jira:sync-worklogs --hours=4 --async')
    ->twiceDaily(12, 15) // 12 PM and 3 PM
    ->weekdays()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->when(function () {
        return config('jira.enable_frequent_worklog_sync', false);
    });
```

### Cron Job Setup

For the Laravel scheduler to work, ensure you have a cron job configured:

```bash
# Edit crontab
crontab -e

# Add this line (adjust path as needed)
* * * * * cd /path/to/jira-reporter && php artisan schedule:run >> /dev/null 2>&1
```

### Verify Scheduler

Test the scheduler configuration:

```bash
# List scheduled commands
php artisan schedule:list

# Run scheduler manually (for testing)
php artisan schedule:run

# Test specific worklog sync command
php artisan schedule:test "jira:sync-worklogs --hours=24 --async"
```

## Admin Interface Setup

### Access the Admin Interface

1. Navigate to `/admin/jira/sync` in your browser
2. You should see the new "Incremental Worklog Sync" panel
3. The panel includes:
   - Last sync statistics
   - Timeframe selection (Last 24 Hours, Last 7 Days, All Worklogs)
   - Project selection with sync status indicators
   - "Sync Worklogs Now" button
   - Real-time progress tracking
   - Validation results display

### UI Configuration

The admin interface loads worklog statistics automatically. If you encounter issues:

```bash
# Clear application cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Rebuild frontend assets
npm run build
```

## Validation Setup

### Enable Validation

Validation is enabled by default. To customize:

```php
// In config/jira.php
'enable_validation' => true,              // Enable/disable validation
'max_discrepancy_percent' => 5.0,         // Maximum acceptable discrepancy
```

### Validation Commands

Test validation functionality:

```bash
# View validation report for all projects
php artisan jira:worklog-validation

# View detailed validation for specific projects
php artisan jira:worklog-validation --projects=DEMO,TEST --detailed

# Export validation results
php artisan jira:worklog-validation --export=csv

# View validation summary only
php artisan jira:worklog-validation --summary
```

## Testing the Setup

### 1. Manual Sync Test

```bash
# Test with dry run first
php artisan jira:sync-worklogs --dry-run

# Run actual sync
php artisan jira:sync-worklogs --hours=24

# Check results
php artisan jira:sync-worklogs --status
```

### 2. Background Job Test

```bash
# Run sync as background job
php artisan jira:sync-worklogs --hours=8 --async

# Monitor job progress
php artisan horizon:status
php artisan queue:monitor jira-worklog-sync
```

### 3. Validation Test

```bash
# Run sync with validation
php artisan jira:sync-worklogs --hours=24

# Check validation results
php artisan jira:worklog-validation --summary
```

### 4. Admin Interface Test

1. Open `/admin/jira/sync` in browser
2. Select projects and timeframe
3. Click "Sync Worklogs Now"
4. Verify real-time progress updates
5. Check validation results after completion

## Troubleshooting

### Common Issues

#### 1. Queue Not Processing

```bash
# Check Horizon status
php artisan horizon:status

# Restart Horizon
php artisan horizon:terminate
php artisan horizon

# Check Redis connection
php artisan tinker --execute="Redis::ping()"
```

#### 2. Sync Failing

```bash
# Check logs
php artisan pail

# Debug sync process
php artisan jira:sync-debug

# Check JIRA connection
php artisan tinker --execute="app(\App\Services\JiraApiServiceV3::class)->testConnection()"
```

#### 3. Validation Errors

```bash
# Check validation configuration
php artisan config:show jira.enable_validation

# Run validation manually
php artisan jira:worklog-validation --projects=DEMO --detailed

# Check JIRA API connectivity
php artisan jira:test-app
```

#### 4. Scheduler Not Running

```bash
# Check cron job
crontab -l

# Test scheduler
php artisan schedule:run

# Check scheduled commands
php artisan schedule:list
```

### Performance Issues

#### 1. Large Project Handling

For projects with many worklogs:

```bash
# Use smaller time windows
php artisan jira:sync-worklogs --hours=4

# Sync specific projects
php artisan jira:sync-worklogs --projects=LARGE_PROJECT --hours=24
```

#### 2. Memory Issues

Increase memory limits if needed:

```bash
# Increase PHP memory limit
php -d memory_limit=512M artisan jira:sync-worklogs

# Check current memory usage
php artisan tinker --execute="echo memory_get_usage(true) / 1024 / 1024 . ' MB'"
```

### Monitoring

#### 1. Sync Status Monitoring

```bash
# Regular status check
php artisan jira:sync-worklogs --status

# Detailed validation report
php artisan jira:worklog-validation --summary

# Check recent sync history
php artisan jira:sync:monitor
```

#### 2. Queue Monitoring

```bash
# Monitor queue status
php artisan queue:monitor jira-worklog-sync

# Check failed jobs
php artisan horizon:failed

# View queue statistics in Horizon dashboard
# Visit /horizon in your browser
```

## Performance Optimization

### 1. Database Optimization

```bash
# Ensure proper indexes exist
php artisan db:show --table=jira_worklog_sync_statuses

# Analyze query performance
php artisan tinker --execute="DB::enableQueryLog()"
```

### 2. Queue Optimization

Adjust queue settings in `config/horizon.php` based on your needs:

```php
'jira-worklog-sync' => [
    'maxProcesses' => 3,        // Increase for more concurrent processing
    'maxTime' => 1800,          // Adjust timeout as needed
    'memory' => 512,            // Increase memory if needed
],
```

### 3. API Rate Limiting

Adjust JIRA API settings in `config/jira.php`:

```php
'requests_per_second' => 10,           // Adjust based on JIRA instance limits
'max_concurrent_requests' => 3,       // Increase for faster processing
```

## Security Considerations

### 1. API Token Security

Ensure JIRA API tokens are properly encrypted:

```bash
# Check encryption
php artisan tinker --execute="app(\App\Models\JiraSetting::class)->first()"
```

### 2. Queue Security

Ensure Redis is properly secured:

```bash
# Check Redis configuration
redis-cli CONFIG GET requirepass
```

### 3. Access Control

Ensure proper access controls for admin interface:

```php
// In routes/web.php - verify authentication middleware
Route::middleware(['auth'])->group(function () {
    // Admin routes
});
```

## Monitoring and Maintenance

### Daily Monitoring

Create a daily monitoring script:

```bash
#!/bin/bash
# daily-worklog-sync-check.sh

echo "=== Daily Worklog Sync Status ==="
php artisan jira:sync-worklogs --status

echo -e "\n=== Validation Summary ==="
php artisan jira:worklog-validation --summary

echo -e "\n=== Queue Status ==="
php artisan queue:monitor jira-worklog-sync

echo -e "\n=== Recent Errors ==="
tail -n 50 storage/logs/laravel.log | grep -i "worklog\|validation"
```

### Weekly Maintenance

```bash
#!/bin/bash
# weekly-worklog-maintenance.sh

echo "=== Cleaning up old sync history ==="
php artisan jira:cleanup-old-sync-history --days=30

echo -e "\n=== Validation Report Export ==="
php artisan jira:worklog-validation --export=csv --summary

echo -e "\n=== Performance Analysis ==="
php artisan jira:sync:monitor --performance-report
```

## Support and Documentation

For additional help:

- **Troubleshooting**: [Worklog Sync Troubleshooting](../troubleshooting/WORKLOG_SYNC_TROUBLESHOOTING.md)
- **API Reference**: [Worklog Sync API](../api/WORKLOG_SYNC_API.md)
- **General Issues**: [Troubleshooting Findings](../troubleshooting/troubleshooting-findings.md)
- **Command Reference**: See `CLAUDE.md` for complete command list

---

**Setup Guide Version**: 1.0.0  
**Compatible with Application Version**: 7.0.0+  
**Last Updated**: December 2025