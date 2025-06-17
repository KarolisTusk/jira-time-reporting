# JIRA Sync Debug Guide

## Overview

This guide explains how to use the comprehensive debugging tools implemented for the JIRA sync process to diagnose and resolve sync issues.

## Debug Tools Available

### 1. Command Line Debug Tool

The main debugging interface is the `jira:sync-debug` artisan command.

#### Basic Usage

```bash
# Check current sync status
php artisan jira:sync-debug status

# Show detailed information
php artisan jira:sync-debug status --details

# Focus on specific sync
php artisan jira:sync-debug status --sync-id=123 --details
```

#### Available Actions

**Status Check**
```bash
# Show current sync processes and their status
php artisan jira:sync-debug status

# Shows:
# - Active, failed, and stuck syncs
# - Progress percentages
# - Error counts
# - Duration and timing information
# - Identifies stuck processes automatically
```

**View Logs**
```bash
# Show recent sync logs
php artisan jira:sync-debug logs

# Show logs for specific sync
php artisan jira:sync-debug logs --sync-id=123 --details

# Shows:
# - Timestamped log entries
# - Error messages with context
# - Operation progress
# - Error categories and severity
```

**System Diagnostics**
```bash
# Run comprehensive system tests
php artisan jira:sync-debug test

# Tests:
# - Database connectivity
# - JIRA API connection
# - Queue worker status
# - Memory and disk usage
# - Sync prerequisites
```

**Cleanup Stuck Syncs**
```bash
# Clean up stuck sync processes
php artisan jira:sync-debug cleanup

# With force flag (no confirmation)
php artisan jira:sync-debug cleanup --force

# Marks stuck syncs as failed and logs cleanup action
```

**Recovery Operations**
```bash
# Attempt to recover failed syncs
php artisan jira:sync-debug recover

# Recover specific sync
php artisan jira:sync-debug recover --sync-id=123

# Resets sync status and re-dispatches job
```

### 2. Enhanced Error Service

The `SyncErrorService` provides intelligent error analysis and reporting.

#### Features

- **Error Categorization**: Automatically categorizes errors (network, JIRA API, memory, database, etc.)
- **Severity Assessment**: Assigns severity levels (critical, high, medium, low)
- **Retry Analysis**: Determines if errors are retryable
- **Smart Suggestions**: Provides actionable suggestions for resolving errors
- **Error Statistics**: Tracks error patterns and frequency

#### Error Categories

1. **Network Errors**: Connection timeouts, network issues
2. **JIRA API Errors**: Authentication, permissions, rate limits
3. **Memory Errors**: Memory exhaustion, resource limits
4. **Database Errors**: Query failures, connection issues
5. **Validation Errors**: Data format, missing fields
6. **Permission Errors**: Access denied, forbidden operations

### 3. Comprehensive Test Suite

Run the test suite to verify debugging functionality:

```bash
# Run all sync debug tests
php artisan test tests/Feature/SyncDebugTest.php

# Tests include:
# - Stuck sync detection
# - Error analysis and categorization
# - Recovery mechanisms
# - Diagnostic capabilities
```

## Common Issues and Solutions

### Issue: Syncs Stuck at 0%

**Symptoms**: Sync processes remain at 0% progress for extended periods

**Diagnosis**:
```bash
php artisan jira:sync-debug status --details
```

**Solutions**:
1. Clean up stuck processes: `php artisan jira:sync-debug cleanup --force`
2. Clear failed jobs: `php artisan queue:flush`
3. Check queue workers: `php artisan queue:work --timeout=300`
4. Run diagnostics: `php artisan jira:sync-debug test`

### Issue: Multiple Failed Jobs

**Symptoms**: Many failed jobs in queue, syncs not progressing

**Diagnosis**:
```bash
php artisan queue:failed
php artisan jira:sync-debug test
```

**Solutions**:
1. Clear failed jobs: `php artisan queue:flush`
2. Check JIRA API credentials
3. Verify network connectivity
4. Review error logs for patterns

### Issue: Memory or Resource Exhaustion

**Symptoms**: Syncs fail with memory errors

**Diagnosis**:
```bash
php artisan jira:sync-debug test
php artisan jira:sync-debug logs --details
```

**Solutions**:
1. Increase PHP memory limit
2. Reduce batch sizes in sync configuration
3. Process fewer projects simultaneously
4. Run syncs during off-peak hours

### Issue: Authentication or Permission Errors

**Symptoms**: 401/403 errors in logs

**Diagnosis**:
```bash
php artisan jira:sync-debug logs --details
```

**Solutions**:
1. Verify JIRA API credentials
2. Check API token permissions
3. Ensure user has access to projects
4. Test JIRA connection manually

## Navigation and UI Issues

### Issue: Navigation Items Not Appearing

**Symptoms**: Menu items like "Issues Browser" or other navigation elements don't appear in the sidebar

**Root Cause Analysis**:
The navigation system uses a Vue.js component architecture where:
1. `AppSidebar.vue` defines navigation items as data arrays
2. `NavMain.vue` receives these items as props and renders them
3. Frontend assets must be rebuilt after navigation changes

**Diagnosis Steps**:

1. **Check Browser Console for Debug Messages**:
   ```javascript
   // Look for these console messages:
   🔍 AppSidebar.vue loaded - using NavMain component!
   🔍 Navigation items defined: [array]
   🔍 NavMain.vue loaded
   🔍 NavMain received items: [array]
   🔍 NavMain items updated: [array]
   ```

2. **Verify Navigation Item Count**:
   ```javascript
   // Admin section should show 4 subitems:
   Item 2: Admin has subitems: 4
   SubItem 0: Enhanced JIRA Sync
   SubItem 1: Issues Browser  
   SubItem 2: Sync History
   SubItem 3: Settings
   ```

3. **Check for JavaScript Errors**:
   - Open browser Developer Tools (F12)
   - Look for import errors or component loading failures
   - Check for TypeScript compilation errors

**Common Solutions**:

1. **Rebuild Frontend Assets**:
   ```bash
   npm run build
   # Clear Laravel caches
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan cache:clear
   ```

2. **Verify Route Definitions**:
   ```bash
   # Check if routes exist
   php artisan route:list | grep -E "(jira|issues|sync-history)"
   ```

3. **Check Component Architecture**:
   - Ensure `AppSidebar.vue` imports `NavMain.vue`
   - Verify navigation items are passed as props to `NavMain`
   - Confirm icon imports are successful

4. **Debug Navigation Creation**:
   Add debugging to `AppSidebar.vue`:
   ```javascript
   console.log('🔍 Creating navigation items...');
   console.log('🔍 Admin subitems created:', adminItems);
   console.log('🔍 Final navigation items:', mainNavItems);
   ```

**Technical Details**:
- Navigation uses Laravel Starter Kit's component system
- Items are defined in `resources/js/components/AppSidebar.vue`
- Rendered by `resources/js/components/NavMain.vue`
- Icons imported from `lucide-vue-next`
- Routes must exist in Laravel routing files

### Issue: UI Changes Not Taking Effect

**Symptoms**: Code changes to Vue components don't appear in browser

**Solutions**:
1. **Force Asset Rebuild**:
   ```bash
   rm -rf public/build
   npm run build
   ```

2. **Clear All Caches**:
   ```bash
   php artisan optimize:clear
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan cache:clear
   ```

3. **Hard Refresh Browser**:
   - Ctrl+F5 (Windows/Linux)
   - Cmd+Shift+R (Mac)
   - Or disable browser cache in Developer Tools

## Best Practices

### 1. Regular Monitoring

- Check sync status regularly: `php artisan jira:sync-debug status`
- Monitor for stuck processes
- Review error patterns weekly

### 2. Proactive Maintenance

- Clean up stuck syncs before they accumulate
- Clear failed jobs periodically
- Run diagnostics before major sync operations

### 3. Error Analysis

- Review error categories to identify patterns
- Address critical and high-severity errors immediately
- Use error suggestions to resolve issues quickly

### 4. Performance Optimization

- Monitor memory and disk usage
- Adjust batch sizes based on system capacity
- Schedule syncs during low-traffic periods

## Integration with Frontend

The debug tools can be integrated into the admin interface using the `SyncDebugDashboard.vue` component:

```vue
<template>
  <SyncDebugDashboard 
    :auto-refresh="true" 
    :refresh-interval="30000" 
  />
</template>
```

### Dashboard Features

- Real-time sync monitoring
- Interactive error analysis
- One-click recovery actions
- System health indicators
- Detailed sync information

## Command Reference

```bash
# Status and monitoring
php artisan jira:sync-debug status [--details] [--sync-id=ID]
php artisan jira:sync-debug logs [--details] [--sync-id=ID]

# Diagnostics and testing
php artisan jira:sync-debug test

# Maintenance operations
php artisan jira:sync-debug cleanup [--force] [--sync-id=ID]
php artisan jira:sync-debug recover [--force] [--sync-id=ID]

# Queue management
php artisan queue:failed
php artisan queue:flush
php artisan queue:work --timeout=300

# Testing
php artisan test tests/Feature/SyncDebugTest.php
```

## Troubleshooting Workflow

1. **Identify Issue**: Use `status` command to see current state
2. **Gather Details**: Use `logs` command for specific sync information
3. **Run Diagnostics**: Use `test` command to check system health
4. **Take Action**: Use `cleanup` or `recover` commands as needed
5. **Verify Fix**: Re-run diagnostics and status checks
6. **Monitor**: Continue monitoring to ensure stability

## Emergency Recovery

If sync system is completely stuck:

```bash
# 1. Stop all queue workers
# 2. Clean up all stuck syncs
php artisan jira:sync-debug cleanup --force

# 3. Clear all failed jobs
php artisan queue:flush

# 4. Clear any pending jobs
php artisan queue:clear

# 5. Run full diagnostics
php artisan jira:sync-debug test

# 6. Restart queue workers
php artisan queue:work --timeout=300
```

This comprehensive debugging system provides full visibility into sync operations and enables quick resolution of common issues.

## Case Study: Navigation Items Missing from Sidebar

### Problem Description
After implementing the JIRA Issues Browser feature, the navigation item was not appearing in the Admin section of the sidebar, despite the route being properly defined and the component being created.

### Investigation Process

**Step 1: Initial Assumptions**
- Initially assumed the issue was with Vue component files not being updated
- Tried editing `AppSidebar.vue` directly with hardcoded navigation items
- Multiple rebuilds and cache clears didn't resolve the issue

**Step 2: Architecture Discovery**
- Found that the navigation system uses a component hierarchy:
  - `AppSidebar.vue` → defines navigation data
  - `NavMain.vue` → receives data as props and renders navigation
- This explained why direct edits to the template weren't working

**Step 3: Debug Implementation**
- Added comprehensive console logging to both components
- Used browser Developer Tools to trace component loading and data flow
- Implemented try-catch blocks to identify JavaScript errors

**Step 4: Root Cause Identification**
- Console debugging revealed that only 2 Admin subitems were being created instead of 4
- The "Issues Browser" and "Sync History" items were missing from the navigation array
- No JavaScript errors were present, indicating a logic issue in navigation creation

### Key Findings

1. **Component Architecture**: Laravel Starter Kit uses a props-based navigation system
2. **Debug Strategy**: Browser console logging is essential for Vue.js debugging
3. **Asset Building**: Frontend changes require `npm run build` to take effect
4. **Cache Management**: Multiple cache layers (Laravel + browser) can mask changes

### Debugging Tools Used

```javascript
// AppSidebar.vue debugging
console.log('🔍 AppSidebar.vue loaded - using NavMain component!');
console.log('🔍 Navigation items defined:', mainNavItems);
console.log('🔍 Admin section items count:', mainNavItems[2]?.items?.length);

// NavMain.vue debugging  
console.log('🔍 NavMain.vue loaded');
console.log('🔍 NavMain received items:', props.items);
watchEffect(() => {
    props.items.forEach((item, index) => {
        console.log(`🔍 Item ${index}:`, item.title, 'has subitems:', item.items?.length || 0);
    });
});
```

### Resolution Status
**Status**: Investigation in progress
**Next Steps**: 
1. Analyze detailed console output to identify why navigation array is incomplete
2. Check for potential JavaScript errors during navigation creation
3. Verify icon imports and route definitions
4. Test with simplified navigation structure to isolate the issue

### Lessons Learned

1. **Always check browser console first** when Vue.js components aren't behaving as expected
2. **Understand the component architecture** before making changes
3. **Use systematic debugging** with console.log statements to trace data flow
4. **Clear all caches** (Laravel + browser) when testing frontend changes
5. **Verify routes exist** before adding navigation items

### Recommended Debugging Approach for Similar Issues

1. **Identify the component hierarchy**:
   ```bash
   grep -r "NavMain\|AppSidebar" resources/js/components/
   ```

2. **Add comprehensive logging**:
   ```javascript
   console.log('🔍 Component loaded');
   console.log('🔍 Data received:', props);
   console.log('🔍 Processing result:', result);
   ```

3. **Verify routes exist**:
   ```bash
   php artisan route:list | grep "target-route"
   ```

4. **Force clean rebuild**:
   ```bash
   rm -rf public/build
   npm run build
   php artisan optimize:clear
   ```

5. **Test in browser with Developer Tools open**:
   - Check Console for errors and debug messages
   - Verify network requests are successful
   - Inspect Vue component data in Vue DevTools if available

This case study demonstrates the importance of systematic debugging and understanding the underlying architecture when troubleshooting frontend issues in Laravel applications. 