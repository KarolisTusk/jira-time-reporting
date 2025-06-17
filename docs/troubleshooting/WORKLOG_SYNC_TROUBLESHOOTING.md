# Worklog Sync Troubleshooting Guide

## Overview

This guide provides comprehensive troubleshooting for the Incremental Worklog Sync feature introduced in Version 7.0. It covers common issues, diagnostic procedures, and solutions for worklog synchronization problems.

## Quick Diagnostics

### 1. System Health Check

Run these commands to quickly assess system health:

```bash
# Check basic sync functionality
php artisan jira:sync-worklogs --dry-run

# Verify queue system
php artisan horizon:status
php artisan queue:monitor jira-worklog-sync

# Check JIRA connectivity
php artisan jira:test-app

# View sync status
php artisan jira:sync-worklogs --status
```

### 2. Common Error Patterns

Look for these patterns in logs (`storage/logs/laravel.log`):

```bash
# Check for worklog sync errors
tail -f storage/logs/laravel.log | grep -i "worklog"

# Look for validation errors
grep -i "validation.*failed" storage/logs/laravel.log

# Check for queue failures
grep -i "jira-worklog-sync.*failed" storage/logs/laravel.log
```

## Common Issues and Solutions

### 1. Worklog Sync Not Starting

#### Symptoms
- "Sync Worklogs Now" button doesn't respond
- Console command hangs or exits immediately
- No jobs appearing in queue

#### Diagnosis
```bash
# Check if projects are configured
php artisan tinker --execute="App\Models\JiraProject::count()"

# Verify JIRA settings
php artisan tinker --execute="App\Models\JiraSetting::first()"

# Check queue connection
php artisan queue:failed
```

#### Solutions

**Missing JIRA Configuration:**
```bash
# Navigate to JIRA settings
# Visit /settings/jira in browser and configure:
# - JIRA URL
# - Email address
# - API token
# - Project keys
```

**Queue System Issues:**
```bash
# Restart Horizon
php artisan horizon:terminate
php artisan horizon

# Clear failed jobs
php artisan horizon:clear

# Restart Redis (if needed)
brew services restart redis  # macOS
sudo systemctl restart redis # Linux
```

**Project Selection Issues:**
```bash
# Verify projects exist
php artisan jira:sync-worklogs --projects=YOUR_PROJECT --dry-run

# Check project keys in settings
php artisan tinker --execute="App\Models\JiraSetting::first()->project_keys"
```

### 2. Sync Fails with Timeout Errors

#### Symptoms
- Sync stops after 30 minutes
- "Maximum execution time exceeded" errors
- Partial sync completion

#### Diagnosis
```bash
# Check sync duration
php artisan jira:sync:monitor

# Look for timeout patterns
grep -i "timeout\|execution time" storage/logs/laravel.log

# Check project size
php artisan tinker --execute="App\Models\JiraWorklog::where('updated_at', '>=', now()->subDay())->count()"
```

#### Solutions

**Increase Timeout for Large Projects:**
```php
// In config/horizon.php
'jira-worklog-sync' => [
    'maxTime' => 3600,      // Increase to 1 hour
    'timeout' => 3600,      // Match maxTime
    'memory' => 512,        // Increase memory if needed
],
```

**Use Smaller Time Windows:**
```bash
# Instead of 24 hours, use smaller windows
php artisan jira:sync-worklogs --hours=8
php artisan jira:sync-worklogs --hours=4

# Sync specific projects
php artisan jira:sync-worklogs --projects=LARGE_PROJECT --hours=12
```

**Split Large Syncs:**
```bash
# Sync projects individually
for project in PROJECT1 PROJECT2 PROJECT3; do
    php artisan jira:sync-worklogs --projects=$project --hours=24 --async
    sleep 300  # Wait 5 minutes between projects
done
```

### 3. JIRA API Rate Limiting

#### Symptoms
- "Too Many Requests" errors
- Sync becomes very slow
- 429 HTTP status codes in logs

#### Diagnosis
```bash
# Check for rate limit errors
grep -i "429\|rate limit\|too many requests" storage/logs/laravel.log

# Monitor API request frequency
tail -f storage/logs/laravel.log | grep -i "jira.*request"
```

#### Solutions

**Adjust Rate Limiting:**
```php
// In config/jira.php
'requests_per_second' => 5,        // Reduce from 10 to 5
'max_concurrent_requests' => 2,    // Reduce from 3 to 2
```

**Use Async Processing:**
```bash
# Always use async for production
php artisan jira:sync-worklogs --hours=24 --async

# Enable in scheduler (routes/console.php)
Schedule::command('jira:sync-worklogs --hours=24 --async')
```

**Implement Delays:**
```bash
# Add delays between syncs
php artisan jira:sync-worklogs --projects=PROJECT1 --async
sleep 600  # Wait 10 minutes
php artisan jira:sync-worklogs --projects=PROJECT2 --async
```

### 4. Validation Failures

#### Symptoms
- High discrepancy percentages
- Validation warnings in UI
- "Validation failed" messages

#### Diagnosis
```bash
# Check validation report
php artisan jira:worklog-validation --detailed

# Look for specific validation errors
php artisan jira:worklog-validation --projects=PROBLEMATIC_PROJECT --detailed

# Check validation configuration
php artisan config:show jira.enable_validation
php artisan config:show jira.max_discrepancy_percent
```

#### Solutions

**High Discrepancy Issues:**
```bash
# Check for missing worklogs
php artisan jira:worklog-validation --projects=PROJECT --detailed | grep -i "missing"

# Compare with JIRA directly
# Visit PROJECT in JIRA and verify worklog counts

# Re-run full sync if discrepancy is too high
php artisan jira:daily-sync --projects=PROJECT --force
```

**Validation Configuration:**
```php
// In config/jira.php - adjust tolerance
'max_discrepancy_percent' => 10.0,  // Increase from 5.0 if needed

// Disable validation temporarily if problematic
'enable_validation' => false,
```

**Data Quality Issues:**
```bash
# Check for data integrity problems
php artisan tinker --execute="
App\Models\JiraWorklog::whereNull('jira_worklog_id')->count()
"

# Look for malformed data
php artisan tinker --execute="
App\Models\JiraWorklog::where('time_spent_seconds', '<=', 0)->count()
"
```

### 5. Resource Type Classification Issues

#### Symptoms
- Most worklogs classified as "general"
- Incorrect resource type assignments
- Resource type warnings in validation

#### Diagnosis
```bash
# Check resource type distribution
php artisan tinker --execute="
App\Models\JiraWorklog::select('resource_type', DB::raw('count(*) as count'))
    ->groupBy('resource_type')
    ->get()
"

# Look for classification warnings
grep -i "resource.*type.*warning" storage/logs/laravel.log
```

#### Solutions

**Update Classification Keywords:**
```php
// In app/Services/JiraWorklogIncrementalSyncService.php
// Modify the $resourceTypeMapping array to add keywords:

private array $resourceTypeMapping = [
    'frontend' => [
        'keywords' => [
            'frontend', 'front-end', 'fe', 'ui', 'ux', 
            'react', 'vue', 'angular', 'javascript', 'css', 'html',
            // Add your project-specific keywords
            'dashboard', 'interface', 'design'
        ],
        'priority' => 1,
    ],
    // ... add more keywords for other types
];
```

**Force Reclassification:**
```bash
# Run sync with reclassification
php artisan jira:daily-sync --projects=PROJECT --reclassify

# Check results
php artisan jira:worklog-validation --projects=PROJECT --detailed
```

### 6. Memory and Performance Issues

#### Symptoms
- "Allowed memory size exhausted" errors
- Very slow sync performance
- Server becomes unresponsive

#### Diagnosis
```bash
# Monitor memory usage during sync
top -p $(pgrep -f "jira:sync-worklogs")

# Check PHP memory limit
php -i | grep memory_limit

# Look for memory errors
grep -i "memory.*exhausted" storage/logs/laravel.log
```

#### Solutions

**Increase Memory Limits:**
```php
// In config/horizon.php
'jira-worklog-sync' => [
    'memory' => 1024,  // Increase from 256 to 1024 MB
],
```

**PHP Configuration:**
```ini
; In php.ini
memory_limit = 1024M
max_execution_time = 3600
```

**Optimize Queries:**
```bash
# Use smaller batches
php artisan jira:sync-worklogs --hours=4

# Process fewer projects at once
php artisan jira:sync-worklogs --projects=SINGLE_PROJECT
```

### 7. Database Connection Issues

#### Symptoms
- "Database connection lost" errors
- "Too many connections" errors
- Sync hanging indefinitely

#### Diagnosis
```bash
# Check database connections
php artisan tinker --execute="DB::select('SELECT count(*) as connections FROM pg_stat_activity')"

# Monitor PostgreSQL connections
psql -d jira_reporter -c "SELECT count(*) FROM pg_stat_activity WHERE state = 'active';"

# Check database logs
tail -f /usr/local/var/log/postgresql@14.log
```

#### Solutions

**Database Configuration:**
```php
// In config/database.php
'pgsql' => [
    // ... existing config ...
    'options' => [
        PDO::ATTR_PERSISTENT => false,  // Disable persistent connections
        PDO::ATTR_TIMEOUT => 300,       // 5 minute timeout
    ],
],
```

**Connection Pool Management:**
```bash
# Restart PostgreSQL
brew services restart postgresql@14  # macOS
sudo systemctl restart postgresql    # Linux

# Check for long-running queries
psql -d jira_reporter -c "SELECT pid, now() - pg_stat_activity.query_start AS duration, query FROM pg_stat_activity WHERE state = 'active' ORDER BY duration DESC;"
```

### 8. Scheduler Issues

#### Symptoms
- Automated syncs not running
- Scheduler commands not executing
- Missing cron job execution

#### Diagnosis
```bash
# Check scheduled commands
php artisan schedule:list

# Test scheduler manually
php artisan schedule:run

# Check cron job
crontab -l | grep schedule

# Look for scheduler errors
grep -i "schedule\|cron" storage/logs/laravel.log
```

#### Solutions

**Cron Job Setup:**
```bash
# Edit crontab
crontab -e

# Add Laravel scheduler (adjust path)
* * * * * cd /path/to/jira-reporter && php artisan schedule:run >> /dev/null 2>&1
```

**Test Specific Commands:**
```bash
# Test worklog sync schedule
php artisan schedule:test "jira:sync-worklogs --hours=24 --async"

# Run manually to verify functionality
php artisan jira:sync-worklogs --hours=24 --async
```

**Debug Scheduler:**
```bash
# Enable scheduler logging
# In routes/console.php, add:
Schedule::command('jira:sync-worklogs --hours=24 --async')
    ->dailyAt('09:00')
    ->sendOutputTo(storage_path('logs/scheduler.log'))
    ->emailOutputOnFailure('admin@example.com');
```

## Advanced Diagnostics

### 1. Deep Sync Analysis

```bash
# Create diagnostic script
cat > worklog_sync_diagnostics.sh << 'EOF'
#!/bin/bash

echo "=== JIRA Worklog Sync Diagnostics ==="
echo "Date: $(date)"
echo

echo "=== System Status ==="
php artisan --version
php artisan horizon:status
echo

echo "=== Database Status ==="
php artisan tinker --execute="
echo 'Projects: ' . App\Models\JiraProject::count();
echo 'Issues: ' . App\Models\JiraIssue::count();
echo 'Worklogs: ' . App\Models\JiraWorklog::count();
echo 'Sync Statuses: ' . App\Models\JiraWorklogSyncStatus::count();
"
echo

echo "=== Recent Sync Activity ==="
php artisan jira:sync-worklogs --status
echo

echo "=== Validation Summary ==="
php artisan jira:worklog-validation --summary
echo

echo "=== Queue Status ==="
php artisan queue:monitor jira-worklog-sync
echo

echo "=== Recent Errors ==="
tail -n 20 storage/logs/laravel.log | grep -i "error\|exception"
EOF

chmod +x worklog_sync_diagnostics.sh
./worklog_sync_diagnostics.sh
```

### 2. Performance Profiling

```bash
# Create performance monitoring script
cat > worklog_sync_performance.php << 'EOF'
<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Start timing
$start = microtime(true);
$startMemory = memory_get_usage(true);

echo "Starting worklog sync performance test...\n";

// Run sync
$exitCode = $kernel->call('jira:sync-worklogs', ['--hours' => 1, '--dry-run' => true]);

// End timing
$end = microtime(true);
$endMemory = memory_get_usage(true);

echo "Performance Results:\n";
echo "Duration: " . round($end - $start, 2) . " seconds\n";
echo "Memory used: " . round(($endMemory - $startMemory) / 1024 / 1024, 2) . " MB\n";
echo "Exit code: $exitCode\n";
EOF

php worklog_sync_performance.php
```

### 3. API Connectivity Testing

```bash
# Test JIRA API connectivity
php artisan tinker --execute="
\$service = app(\App\Services\JiraApiServiceV3::class);
try {
    \$result = \$service->testConnection();
    echo 'API Connection: ' . (\$result ? 'SUCCESS' : 'FAILED') . PHP_EOL;
} catch (Exception \$e) {
    echo 'API Error: ' . \$e->getMessage() . PHP_EOL;
}
"

# Test specific project access
php artisan tinker --execute="
\$service = app(\App\Services\JiraApiServiceV3::class);
try {
    \$issues = \$service->searchIssues('project = \"YOUR_PROJECT\" ORDER BY updated DESC', 0, 1);
    echo 'Project Access: ' . (count(\$issues['issues']) > 0 ? 'SUCCESS' : 'NO_ISSUES') . PHP_EOL;
} catch (Exception \$e) {
    echo 'Project Error: ' . \$e->getMessage() . PHP_EOL;
}
"
```

## Prevention and Monitoring

### 1. Proactive Monitoring

Create monitoring scripts:

```bash
# Daily health check
cat > daily_worklog_check.sh << 'EOF'
#!/bin/bash

LOG_FILE="worklog_sync_health.log"

{
    echo "=== $(date) ==="
    
    # Check if sync is stuck
    SYNC_STATUS=$(php artisan jira:sync-worklogs --status 2>/dev/null | grep -c "In Progress")
    if [ "$SYNC_STATUS" -gt 0 ]; then
        echo "WARNING: Sync appears to be stuck"
    fi
    
    # Check for recent errors
    ERROR_COUNT=$(tail -n 100 storage/logs/laravel.log | grep -c "ERROR.*worklog")
    if [ "$ERROR_COUNT" -gt 5 ]; then
        echo "WARNING: High error count: $ERROR_COUNT"
    fi
    
    # Check queue health
    if ! php artisan horizon:status | grep -q "running"; then
        echo "ERROR: Horizon not running"
    fi
    
    echo "Health check completed"
    echo
} >> "$LOG_FILE"
EOF

# Add to crontab for daily execution
# 0 8 * * * /path/to/daily_worklog_check.sh
```

### 2. Alert System

```bash
# Create alert script for critical issues
cat > worklog_sync_alerts.sh << 'EOF'
#!/bin/bash

ALERT_EMAIL="admin@example.com"

# Check for critical errors
CRITICAL_ERRORS=$(tail -n 50 storage/logs/laravel.log | grep -c "CRITICAL\|EMERGENCY")

if [ "$CRITICAL_ERRORS" -gt 0 ]; then
    echo "Critical worklog sync errors detected: $CRITICAL_ERRORS" | \
    mail -s "JIRA Worklog Sync Alert" "$ALERT_EMAIL"
fi

# Check for long-running syncs
LONG_RUNNING=$(php artisan jira:sync:monitor | grep -c "duration.*[2-9][0-9][0-9][0-9]")

if [ "$LONG_RUNNING" -gt 0 ]; then
    echo "Long-running worklog sync detected" | \
    mail -s "JIRA Worklog Sync Performance Alert" "$ALERT_EMAIL"
fi
EOF
```

## Emergency Recovery

### 1. Stuck Sync Recovery

```bash
# Kill stuck sync processes
php artisan horizon:terminate

# Clear stuck jobs
php artisan horizon:clear

# Clean up database
php artisan tinker --execute="
App\Models\JiraSyncHistory::where('status', 'in_progress')
    ->where('updated_at', '<', now()->subHours(2))
    ->update(['status' => 'failed', 'error_details' => 'Manually terminated due to timeout']);
"

# Restart system
php artisan horizon
```

### 2. Data Corruption Recovery

```bash
# Backup current data
pg_dump jira_reporter > backup_before_recovery_$(date +%Y%m%d_%H%M%S).sql

# Reset worklog sync status
php artisan tinker --execute="
App\Models\JiraWorklogSyncStatus::truncate();
"

# Re-run full sync
php artisan jira:daily-sync --force
```

### 3. Complete System Reset

```bash
# Full reset (use with caution)
php artisan horizon:terminate
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Reset queues
redis-cli FLUSHDB

# Restart services
php artisan horizon
php artisan serve
```

## Getting Help

### 1. Collecting Debug Information

Before seeking help, collect this information:

```bash
# System information
php artisan --version
php --version
cat .env | grep -E "APP_ENV|DB_CONNECTION|QUEUE_CONNECTION"

# Sync status
php artisan jira:sync-worklogs --status
php artisan jira:worklog-validation --summary

# Recent logs
tail -n 50 storage/logs/laravel.log | grep -i worklog

# Queue status
php artisan horizon:status
php artisan queue:failed --json
```

### 2. Documentation References

- **Setup Guide**: [Worklog Sync Setup](../setup/WORKLOG_SYNC_SETUP.md)
- **API Reference**: [Worklog Sync API](../api/WORKLOG_SYNC_API.md)
- **General Troubleshooting**: [Troubleshooting Findings](troubleshooting-findings.md)
- **Main Documentation**: [CLAUDE.md](../../CLAUDE.md)

### 3. Support Channels

When reporting issues, include:
- Application version (7.0.0+)
- Error messages from logs
- Steps to reproduce
- System configuration details
- Results of diagnostic commands

---

**Troubleshooting Guide Version**: 1.0.0  
**Compatible with Application Version**: 7.0.0+  
**Last Updated**: December 2025