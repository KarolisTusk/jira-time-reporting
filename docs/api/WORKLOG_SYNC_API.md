# Worklog Sync API Documentation

## Overview

This document provides comprehensive API documentation for the Incremental Worklog Sync feature introduced in Version 7.0. These endpoints enable programmatic control of worklog synchronization, status monitoring, and validation reporting.

## Base URL

All endpoints are prefixed with `/api/jira/sync/` and require authentication.

```
Base URL: {app_url}/api/jira/sync/
Authentication: Required (session-based)
Content-Type: application/json
```

## Authentication

All endpoints require user authentication. Include the CSRF token in requests:

```javascript
// Get CSRF token from meta tag
const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// Include in headers
headers: {
    'X-CSRF-TOKEN': token,
    'Content-Type': 'application/json'
}
```

## Endpoints

### 1. Start Worklog Sync

Start an incremental worklog synchronization for specified projects.

**Endpoint:** `POST /api/jira/sync/worklogs`

#### Request Body

```json
{
    "project_keys": ["DEMO", "TEST"],
    "timeframe": "last24h",
    "sync_type": "worklog_incremental"
}
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `project_keys` | array | Yes | Array of JIRA project keys to sync |
| `timeframe` | string | Yes | Time range: `last24h`, `last7days`, `force_all` |
| `sync_type` | string | No | Always `worklog_incremental` (default) |

#### Validation Rules

- `project_keys`: Required array with at least 1 element
- `project_keys.*`: Must be valid project keys that exist in database
- `timeframe`: Must be one of: `last24h`, `last7days`, `force_all`

#### Response

**Success (200 OK):**
```json
{
    "success": true,
    "message": "Worklog sync started successfully",
    "sync_history_id": 123,
    "estimated_duration": "~5 minutes"
}
```

**Error (422 Unprocessable Entity):**
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "project_keys": ["The project keys field is required."],
        "timeframe": ["The selected timeframe is invalid."]
    }
}
```

**Error (500 Internal Server Error):**
```json
{
    "success": false,
    "message": "Failed to start worklog sync: Connection timeout"
}
```

#### Example Usage

```javascript
// Start worklog sync for multiple projects
const startWorklogSync = async () => {
    try {
        const response = await fetch('/api/jira/sync/worklogs', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                project_keys: ['DEMO', 'TEST'],
                timeframe: 'last24h'
            })
        });

        const result = await response.json();
        
        if (result.success) {
            console.log('Sync started:', result.sync_history_id);
            // Start polling for progress
            pollSyncProgress(result.sync_history_id);
        } else {
            console.error('Sync failed:', result.message);
        }
    } catch (error) {
        console.error('Request failed:', error);
    }
};
```

### 2. Get Worklog Sync Status

Retrieve current sync status for all configured projects.

**Endpoint:** `GET /api/jira/sync/worklogs/status`

#### Response

**Success (200 OK):**
```json
{
    "success": true,
    "stats": {
        "lastSyncFormatted": "2 hours ago",
        "projectsSyncedToday": 3,
        "worklogsProcessedToday": 157
    },
    "project_statuses": {
        "DEMO": "Last synced 2 hours ago",
        "TEST": "Last synced 4 hours ago",
        "PROD": "Never synced"
    }
}
```

#### Example Usage

```javascript
const getWorklogStatus = async () => {
    try {
        const response = await fetch('/api/jira/sync/worklogs/status');
        const result = await response.json();
        
        if (result.success) {
            console.log('Last sync:', result.stats.lastSyncFormatted);
            console.log('Projects synced today:', result.stats.projectsSyncedToday);
        }
    } catch (error) {
        console.error('Failed to get status:', error);
    }
};
```

### 3. Get Worklog Sync Statistics

Retrieve comprehensive worklog sync statistics.

**Endpoint:** `GET /api/jira/sync/worklogs/stats`

#### Response

**Success (200 OK):**
```json
{
    "success": true,
    "stats": {
        "lastSyncFormatted": "2 hours ago",
        "projectsSyncedToday": 3,
        "worklogsProcessedToday": 157,
        "totalProjects": 5,
        "projectsWithErrors": 0
    }
}
```

#### Example Usage

```javascript
const getWorklogStats = async () => {
    try {
        const response = await fetch('/api/jira/sync/worklogs/stats');
        const result = await response.json();
        
        if (result.success) {
            updateStatsDisplay(result.stats);
        }
    } catch (error) {
        console.error('Failed to get stats:', error);
    }
};
```

### 4. Get Validation Results

Retrieve worklog sync validation results and quality metrics.

**Endpoint:** `GET /api/jira/sync/worklogs/validation`

#### Response

**Success (200 OK) - With Results:**
```json
{
    "success": true,
    "validation_summary": {
        "timestamp": "2025-12-16T10:30:00Z",
        "total_projects": 3,
        "projects_passed": 2,
        "projects_failed": 1,
        "average_completeness_score": 92.5,
        "overall_discrepancy_percentage": 2.1,
        "total_errors": 1,
        "total_warnings": 3,
        "critical_issues": [
            "Project TEST has high discrepancy: 8.5%"
        ],
        "recommendations": [
            "Review failed project syncs and re-run worklog sync if necessary",
            "High discrepancy detected - consider running full sync for affected projects"
        ]
    }
}
```

**Success (200 OK) - No Results:**
```json
{
    "success": true,
    "message": "No validation results available",
    "validation_summary": null
}
```

#### Example Usage

```javascript
const getValidationResults = async () => {
    try {
        const response = await fetch('/api/jira/sync/worklogs/validation');
        const result = await response.json();
        
        if (result.success && result.validation_summary) {
            displayValidationResults(result.validation_summary);
        } else {
            console.log('No validation results available');
        }
    } catch (error) {
        console.error('Failed to get validation results:', error);
    }
};
```

### 5. Get Sync Progress

Monitor real-time progress of a specific sync operation.

**Endpoint:** `GET /api/jira/sync/progress/{syncHistoryId}`

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `syncHistoryId` | integer | Yes | ID of sync history record to monitor |

#### Response

**Success (200 OK) - In Progress:**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "status": "in_progress",
        "start_time": "2025-12-16T10:15:00Z",
        "end_time": null,
        "current_message": "Processing worklogs for project DEMO...",
        "progress_percentage": 45,
        "metadata": {
            "worklogs_processed": 89,
            "worklogs_added": 12,
            "worklogs_updated": 5,
            "projects_completed": 1,
            "total_projects": 3,
            "validation_in_progress": false
        },
        "worklog_results": {
            "worklogs_processed": 89,
            "worklogs_added": 12,
            "worklogs_updated": 5,
            "worklogs_skipped": 72,
            "errors": []
        }
    }
}
```

**Success (200 OK) - Completed:**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "status": "completed",
        "start_time": "2025-12-16T10:15:00Z",
        "end_time": "2025-12-16T10:22:00Z",
        "current_message": "Worklog sync completed successfully",
        "progress_percentage": 100,
        "metadata": {
            "worklogs_processed": 157,
            "worklogs_added": 23,
            "worklogs_updated": 8,
            "sync_duration": 420,
            "validation_passed": true,
            "completeness_score": 98.5
        }
    }
}
```

**Error (404 Not Found):**
```json
{
    "success": false,
    "message": "Sync history not found"
}
```

#### Example Usage - Progress Polling

```javascript
const pollSyncProgress = async (syncHistoryId) => {
    const maxAttempts = 60; // 5 minutes with 5-second intervals
    let attempts = 0;

    const poll = async () => {
        if (attempts >= maxAttempts) {
            console.log('Polling timeout reached');
            return;
        }

        try {
            const response = await fetch(`/api/jira/sync/progress/${syncHistoryId}`);
            const result = await response.json();

            if (result.success) {
                updateProgressDisplay(result.data);

                // Check if completed
                if (['completed', 'completed_with_errors', 'failed'].includes(result.data.status)) {
                    console.log('Sync finished with status:', result.data.status);
                    return;
                }

                // Continue polling
                attempts++;
                setTimeout(poll, 5000);
            } else {
                console.error('Progress check failed:', result.message);
            }
        } catch (error) {
            console.error('Progress polling error:', error);
            attempts++;
            setTimeout(poll, 5000);
        }
    };

    poll();
};

const updateProgressDisplay = (data) => {
    // Update progress bar
    const progressBar = document.querySelector('.progress-bar');
    if (progressBar) {
        progressBar.style.width = `${data.progress_percentage}%`;
    }

    // Update message
    const messageElement = document.querySelector('.progress-message');
    if (messageElement) {
        messageElement.textContent = data.current_message;
    }

    // Update statistics
    const metadata = data.metadata || {};
    document.querySelector('.worklogs-processed').textContent = metadata.worklogs_processed || 0;
    document.querySelector('.worklogs-added').textContent = metadata.worklogs_added || 0;
    document.querySelector('.worklogs-updated').textContent = metadata.worklogs_updated || 0;

    // Show validation indicator
    const validationIndicator = document.querySelector('.validation-indicator');
    if (validationIndicator) {
        validationIndicator.style.display = metadata.validation_in_progress ? 'block' : 'none';
    }
};
```

## Error Handling

### Common Error Responses

#### 401 Unauthorized
```json
{
    "message": "Unauthenticated."
}
```

#### 403 Forbidden
```json
{
    "message": "This action is unauthorized."
}
```

#### 422 Validation Error
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "project_keys": ["At least one project key is required"],
        "timeframe": ["Invalid timeframe specified"]
    }
}
```

#### 500 Internal Server Error
```json
{
    "success": false,
    "message": "Internal server error occurred"
}
```

### Error Handling Best Practices

```javascript
const handleApiError = (response, result) => {
    switch (response.status) {
        case 401:
            // Redirect to login
            window.location.href = '/login';
            break;
        
        case 403:
            alert('You do not have permission to perform this action');
            break;
        
        case 422:
            // Display validation errors
            if (result.errors) {
                Object.keys(result.errors).forEach(field => {
                    displayFieldError(field, result.errors[field]);
                });
            }
            break;
        
        case 500:
            alert('Server error occurred. Please try again later.');
            break;
        
        default:
            alert(result.message || 'An unexpected error occurred');
    }
};

// Usage example
const apiCall = async (url, options = {}) => {
    try {
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!response.ok) {
            handleApiError(response, result);
            return null;
        }
        
        return result;
    } catch (error) {
        console.error('Network error:', error);
        alert('Network error occurred. Please check your connection.');
        return null;
    }
};
```

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Sync Operations**: Limited to 5 requests per minute per user
- **Status/Progress Checks**: Limited to 60 requests per minute per user
- **Validation Requests**: Limited to 10 requests per minute per user

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1640502000
```

## Complete Integration Example

Here's a complete example of integrating worklog sync functionality:

```javascript
class WorklogSyncManager {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        this.baseUrl = '/api/jira/sync';
        this.currentSyncId = null;
        this.pollingInterval = null;
    }

    async startSync(projectKeys, timeframe) {
        try {
            const result = await this.apiCall('/worklogs', {
                method: 'POST',
                body: JSON.stringify({
                    project_keys: projectKeys,
                    timeframe: timeframe
                })
            });

            if (result && result.success) {
                this.currentSyncId = result.sync_history_id;
                this.startProgressPolling();
                return result;
            }
        } catch (error) {
            console.error('Failed to start sync:', error);
            throw error;
        }
    }

    async getStatus() {
        return await this.apiCall('/worklogs/status');
    }

    async getValidationResults() {
        return await this.apiCall('/worklogs/validation');
    }

    startProgressPolling() {
        if (!this.currentSyncId) return;

        this.pollingInterval = setInterval(async () => {
            try {
                const result = await this.apiCall(`/progress/${this.currentSyncId}`);
                
                if (result && result.success) {
                    this.onProgressUpdate(result.data);

                    // Stop polling if sync is complete
                    if (['completed', 'completed_with_errors', 'failed'].includes(result.data.status)) {
                        this.stopProgressPolling();
                        this.onSyncComplete(result.data);
                    }
                }
            } catch (error) {
                console.error('Progress polling error:', error);
            }
        }, 5000);
    }

    stopProgressPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    onProgressUpdate(data) {
        // Override this method to handle progress updates
        console.log('Progress update:', data);
    }

    onSyncComplete(data) {
        // Override this method to handle sync completion
        console.log('Sync completed:', data);
    }

    async apiCall(endpoint, options = {}) {
        const url = this.baseUrl + endpoint;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken
            }
        };

        const response = await fetch(url, { ...defaultOptions, ...options });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.message || 'API request failed');
        }

        return result;
    }
}

// Usage
const syncManager = new WorklogSyncManager();

// Override progress handler
syncManager.onProgressUpdate = (data) => {
    updateUIProgress(data);
};

// Override completion handler
syncManager.onSyncComplete = (data) => {
    showSyncResults(data);
    loadValidationResults();
};

// Start sync
document.getElementById('sync-button').addEventListener('click', async () => {
    const projectKeys = getSelectedProjects();
    const timeframe = getSelectedTimeframe();
    
    try {
        await syncManager.startSync(projectKeys, timeframe);
    } catch (error) {
        alert('Failed to start sync: ' + error.message);
    }
});
```

## WebSocket Integration (Optional)

For enhanced real-time updates, the system also supports WebSocket connections for progress broadcasting:

```javascript
// Laravel Echo integration (if configured)
if (window.Echo) {
    Echo.channel(`sync-progress.${userId}`)
        .listen('SyncProgressUpdate', (event) => {
            console.log('Real-time progress update:', event);
            updateProgressDisplay(event.data);
        });
}
```

---

**API Documentation Version**: 1.0.0  
**Compatible with Application Version**: 7.0.0+  
**Last Updated**: December 2025