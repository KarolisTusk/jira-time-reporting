# JIRA Sync Enhancements - Legacy Implementation Tasks

## Overview
This document contains the original sync enhancement tasks that were later superseded by the Enhanced JIRA Synchronization System. These tasks focused on basic progress tracking and queue-based processing.

## Related Documentation
- **Current PRD**: [`prd-enhanced-jira-sync.md`](./prd-enhanced-jira-sync.md) - Latest Enhanced JIRA Synchronization System PRD
- **Current Tasks**: [`tasks-prd-enhanced-jira-sync.md`](./tasks-prd-enhanced-jira-sync.md) - Active implementation tasks
- **Base Implementation**: [`tasks-prd-jira-time-consumption-reporting.md`](./tasks-prd-jira-time-consumption-reporting.md) - Core JIRA reporting foundation

> **Status**: This document represents legacy enhancement tasks. The current implementation is now tracked in `tasks-prd-enhanced-jira-sync.md` which implements the comprehensive Enhanced JIRA Synchronization System PRD.

## Relevant Files

- `app/Models/JiraSyncHistory.php` - Model for storing sync history records (created)
- `app/Models/JiraSyncLog.php` - Model for storing detailed sync log entries (created)
- `database/migrations/2025_06_12_062609_create_jira_sync_histories_table.php` - Migration for sync history table (created)
- `database/migrations/2025_06_12_062743_create_jira_sync_logs_table.php` - Migration for sync logs table (created)
- `app/Services/JiraImportService.php` - Main service that needs enhancement for progress tracking
- `app/Services/JiraSyncProgressService.php` - New service for managing sync progress state (created)
- `app/Http/Controllers/JiraImportController.php` - Controller needs updates for async processing (updated)
- `app/Http/Controllers/JiraSyncHistoryController.php` - New controller for sync history endpoints
- `app/Jobs/ProcessJiraSync.php` - Queue job for async JIRA sync processing (created)
- `app/Events/JiraSyncProgress.php` - Event for broadcasting sync progress updates (created)
- `app/Console/Commands/MonitorJiraSyncJobs.php` - Command for monitoring and managing sync jobs (created)
- `resources/js/pages/settings/Jira.vue` - Settings page needs sync progress UI
- `resources/js/pages/settings/JiraSyncHistory.vue` - New page for viewing sync history
- `resources/js/components/JiraSyncProgress.vue` - New component for real-time sync progress
- `resources/js/composables/useJiraSyncProgress.ts` - Composable for managing sync progress state
- `routes/web.php` - Routes for new sync history endpoints (updated)
- `routes/channels.php` - Broadcasting channels for sync progress
- `QUEUE_SETUP.md` - Queue configuration and setup documentation (created)

### Notes

- The sync process should be moved to a queue job for better performance and real-time updates
- WebSocket/Server-Sent Events will be used for real-time progress updates
- Sync history should be paginated and searchable
- Consider implementing retry logic for failed syncs
- Use `npx jest [optional/path/to/test/file]` to run tests

## Tasks

- [x] 1.0 Create Database Schema for Sync History and Logs
  - [x] 1.1 Create migration for `jira_sync_histories` table with fields: id, started_at, completed_at, status (pending/in_progress/completed/failed), total_projects, processed_projects, total_issues, processed_issues, total_worklogs, processed_worklogs, total_users, processed_users, error_count, error_details (JSON), duration_seconds, triggered_by (user_id), sync_type (manual/scheduled)
  - [x] 1.2 Create migration for `jira_sync_logs` table with fields: id, jira_sync_history_id, timestamp, level (info/warning/error), message, context (JSON), entity_type (project/issue/worklog/user), entity_id, operation (fetch/create/update)
  - [x] 1.3 Create `JiraSyncHistory` model with relationships, scopes for filtering by status, and methods for calculating progress percentage
  - [x] 1.4 Create `JiraSyncLog` model with relationship to sync history and scopes for filtering by level and entity type
  - [x] 1.5 Add indexes on frequently queried columns (status, started_at, triggered_by) for performance
  - [x] 1.6 Run migrations and verify database schema is created correctly

- [x] 2.0 Implement Async Sync Processing with Queue Jobs
  - [x] 2.1 Create `ProcessJiraSync` job class that implements ShouldQueue interface
  - [x] 2.2 Move sync logic from `JiraImportService::importDataForAllConfiguredProjects` to the job's handle method
  - [x] 2.3 Implement job progress tracking using Laravel's job progress features
  - [x] 2.4 Add error handling and retry logic with exponential backoff for API failures
  - [x] 2.5 Update `JiraImportController::triggerImport` to dispatch the job and return immediate response with sync history ID
  - [x] 2.6 Configure queue worker settings in `.env` and ensure Redis/database queue driver is set up
  - [x] 2.7 Add failed job handling to log failures and update sync history status

- [x] 3.0 Implement Real-time Progress Updates with Broadcasting
  - [x] 3.1 Create `JiraSyncProgress` event class with sync history ID and progress data
  - [x] 3.2 Create `JiraSyncProgressService` to manage progress state and calculate estimates
  - [x] 3.3 Implement progress broadcasting at key points in the sync process (project start/end, issue batch processing, worklog processing)
  - [x] 3.4 Set up Laravel Echo and configure broadcasting driver (Pusher/Redis/Socket.io)
  - [x] 3.5 Create private channel authorization in `routes/channels.php` for sync progress updates
  - [x] 3.6 Add methods to track and broadcast: current operation, items processed, time elapsed, estimated time remaining
  - [x] 3.7 Implement progress persistence to handle page refreshes during sync

- [x] 4.0 Develop Sync History Management Features
  - [x] 4.1 Create `JiraSyncHistoryController` with index, show, and destroy methods
  - [x] 4.2 Implement paginated index endpoint with filtering by status, date range, and user
  - [x] 4.3 Create detailed show endpoint that includes associated sync logs
  - [x] 4.4 Add ability to cancel in-progress syncs by terminating the job
  - [x] 4.5 Implement sync retry functionality that creates a new sync based on failed sync parameters
  - [x] 4.6 Add routes for sync history endpoints in `routes/web.php`
  - [x] 4.7 Create API endpoints for fetching sync logs with pagination and filtering

- [ ] 5.0 Create UI Components for Sync Progress and History
  - [x] 5.1 Create `JiraSyncProgress.vue` component with progress bar, current operation display, and time estimates
  - [x] 5.2 Implement `useJiraSyncProgress.ts` composable to manage WebSocket connection and progress state
  - [x] 5.3 Update `Jira.vue` to show sync progress modal/panel when sync is triggered
  - [x] 5.4 Create `JiraSyncHistory.vue` page with data table showing sync history
  - [x] 5.5 Implement sync history table features: sorting, filtering, pagination, and row actions (view details, retry, cancel)
  - [x] 5.6 Create sync detail modal/page showing full sync logs with search and filter capabilities
  - [x] 5.7 Add navigation link to sync history page in settings sidebar
  - [x] 5.8 Implement error display with expandable details and suggested actions
  - [x] 5.9 Add real-time updates to sync history table when new syncs start or complete
  - [x] 5.10 Create unit tests for Vue components and composables 