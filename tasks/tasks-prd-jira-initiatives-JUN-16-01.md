# Task List: JIRA Initiatives Feature Implementation

**Based on PRD:** `/tasks/prd-jira-initiatives.md`  
**Generated:** June 16, 2025  
**Sequence:** 01

## Relevant Files

- `database/migrations/[timestamp]_add_labels_and_epic_to_jira_issues.php` - Migration to add labels and epic fields to jira_issues table
- `database/migrations/[timestamp]_create_initiatives_table.php` - Create initiatives table and related pivot tables
- `app/Models/Initiative.php` - Initiative model with relationships to projects, labels, and users
- `app/Models/InitiativeProjectFilter.php` - Model for initiative project+label combinations
- `app/Models/InitiativeAccess.php` - Model for user access control to initiatives
- `app/Models/JiraIssue.php` - Update to include labels and epic relationships
- `app/Services/JiraInitiativeService.php` - Core business logic for initiative calculations
- `app/Services/EnhancedJiraImportService.php` - Update to import labels and epic data
- `app/Services/JiraWorklogIncrementalSyncService.php` - Update to sync label changes
- `app/Http/Controllers/Admin/InitiativeController.php` - Admin interface for initiative management
- `app/Http/Controllers/Client/InitiativeDashboardController.php` - Client-facing initiative dashboard
- `app/Http/Requests/CreateInitiativeRequest.php` - Validation for initiative creation
- `app/Http/Requests/UpdateInitiativeRequest.php` - Validation for initiative updates
- `resources/js/Pages/Admin/Initiatives/Index.vue` - Admin initiative listing page
- `resources/js/Pages/Admin/Initiatives/Create.vue` - Admin initiative creation form
- `resources/js/Pages/Admin/Initiatives/Edit.vue` - Admin initiative editing form
- `resources/js/Pages/Client/InitiativeDashboard.vue` - Client initiative dashboard
- `resources/js/Components/Initiative/MetricsCard.vue` - Initiative metrics display component
- `resources/js/Components/Initiative/ExportButton.vue` - Excel export functionality component
- `app/Exports/InitiativeWorklogExport.php` - Excel export class for initiative data
- `routes/web.php` - Add initiative routes for admin and client interfaces
- `routes/api.php` - Add API routes for initiative data and exports
- `tests/Feature/InitiativeManagementTest.php` - Tests for initiative CRUD operations
- `tests/Feature/InitiativeAccessTest.php` - Tests for access control functionality
- `tests/Feature/InitiativeCalculationTest.php` - Tests for worklog calculations
- `tests/Feature/InitiativeExportTest.php` - Tests for export functionality

### Notes

- Initiative calculations should be cached to improve performance
- Use background jobs for heavy calculation operations
- Implement proper authorization policies for all initiative-related actions
- Follow existing code patterns for API responses and error handling

## Tasks

- [ ] 1.0 Extend JIRA Data Import to Include Labels and Epics
  - [ ] 1.1 Create migration to add `labels` (JSON) and `epic_key` (string) columns to `jira_issues` table (PRD Req #1)
  - [ ] 1.2 Update `JiraIssue` model to include `labels` and `epic_key` in fillable attributes and add appropriate casts
  - [ ] 1.3 Extend `JiraApiServiceV3` to fetch labels and epic data from JIRA API when retrieving issues
  - [ ] 1.4 Update `EnhancedJiraImportService` to process and store labels and epic information during full sync (PRD Req #1)
  - [ ] 1.5 Add validation to ensure labels are stored as valid JSON array and epic_key follows JIRA format
  - [ ] 1.6 Create database indexes on `labels` and `epic_key` columns for query performance
  - [ ] 1.7 Test label and epic import functionality with sample JIRA data

- [ ] 2.0 Create Initiative Database Schema and Models
  - [ ] 2.1 Create `initiatives` table migration with fields: id, name, description, hourly_rate, is_active, created_at, updated_at (PRD Req #5, #8)
  - [ ] 2.2 Create `initiative_project_filters` table migration for many-to-many relationship: id, initiative_id, jira_project_id, required_labels (JSON), epic_key, created_at (PRD Req #6, #7)
  - [ ] 2.3 Create `initiative_access` table migration for user permissions: id, initiative_id, user_id, access_type (read/admin), created_at, updated_at (PRD Req #10, #11, #12)
  - [ ] 2.4 Create `Initiative` model with relationships to projects, users, and worklog calculation methods
  - [ ] 2.5 Create `InitiativeProjectFilter` model with validation for label format and project existence
  - [ ] 2.6 Create `InitiativeAccess` model with enum for access types and user relationship
  - [ ] 2.7 Add initiative relationships to existing `User` and `JiraProject` models
  - [ ] 2.8 Create database seeders for test initiative data

- [ ] 3.0 Build Initiative Management System (Admin Interface)
  - [ ] 3.1 Create `InitiativeController` with CRUD operations: index, create, store, edit, update, destroy (PRD Req #5, #8)
  - [ ] 3.2 Create `CreateInitiativeRequest` validation class to validate name, description, hourly_rate, and project filters
  - [ ] 3.3 Create `UpdateInitiativeRequest` validation class with same rules as create plus ID validation
  - [ ] 3.4 Build admin initiative index page (`Admin/Initiatives/Index.vue`) showing list of all initiatives with search and filters
  - [ ] 3.5 Build initiative creation form (`Admin/Initiatives/Create.vue`) with project selector, label input, and hourly rate fields (PRD Req #6, #7)
  - [ ] 3.6 Build initiative editing form (`Admin/Initiatives/Edit.vue`) allowing modification of all initiative properties (PRD Req #8)
  - [ ] 3.7 Add initiative management navigation to admin sidebar and update routing in `web.php`
  - [ ] 3.8 Implement initiative deletion with cascade handling for related access records
  - [ ] 3.9 Add form validation and error handling for all initiative management operations

- [ ] 4.0 Implement Initiative Access Control and User Management  
  - [ ] 4.1 Create `InitiativeAccessController` for managing user access to initiatives (PRD Req #10)
  - [ ] 4.2 Create `AssignInitiativeAccessRequest` validation for user and initiative ID validation
  - [ ] 4.3 Build user access management interface showing users and their initiative permissions
  - [ ] 4.4 Create `InitiativePolicy` authorization class to enforce access control (PRD Req #11, #12, #13)
  - [ ] 4.5 Add middleware to protect initiative routes based on user permissions
  - [ ] 4.6 Create helper methods to check if user can access specific initiative data
  - [ ] 4.7 Update user management interface to include initiative access assignment
  - [ ] 4.8 Implement bulk access assignment functionality for efficient user management
  - [ ] 4.9 Add audit logging for initiative access changes

- [ ] 5.0 Create Client Initiative Dashboard and Reporting Interface
  - [ ] 5.1 Create `JiraInitiativeService` for calculating initiative metrics including monthly hours and costs (PRD Req #14, #15)
  - [ ] 5.2 Create `ClientInitiativeDashboardController` to serve initiative data for authorized users (PRD Req #11, #12)
  - [ ] 5.3 Build main client dashboard (`Client/InitiativeDashboard.vue`) showing user's accessible initiatives (PRD Req #23, #24)
  - [ ] 5.4 Create `InitiativeMetricsCard` component to display total hours, costs, and monthly breakdown (PRD Req #14, #15, #16)
  - [ ] 5.5 Create initiative detail view showing contributing issues and their worklog hours (PRD Req #17)
  - [ ] 5.6 Implement date range filtering for initiative reports (custom month/year selection)
  - [ ] 5.7 Add real-time metrics updates using periodic data refresh (PRD Req #18)
  - [ ] 5.8 Create loading states and error handling for initiative data fetching
  - [ ] 5.9 Add responsive design to ensure dashboard works on mobile devices
  - [ ] 5.10 Update main navigation to include client initiative dashboard link

- [ ] 6.0 Develop Initiative Export Functionality
  - [ ] 6.1 Create `InitiativeWorklogExport` class using Laravel Excel for generating Excel reports (PRD Req #19, #20, #21)
  - [ ] 6.2 Implement export data structure including monthly hours breakdown and issue details (PRD Req #19, #20)
  - [ ] 6.3 Add cost calculations to export if user has cost visibility permissions (PRD Req #21)
  - [ ] 6.4 Create `ExportButton` component with date range selection and download functionality (PRD Req #22)
  - [ ] 6.5 Add API endpoint for initiative export with proper authentication and file generation
  - [ ] 6.6 Implement export progress tracking for large datasets using background jobs
  - [ ] 6.7 Add export history tracking to monitor usage and troubleshoot issues
  - [ ] 6.8 Create export templates with proper formatting and branding
  - [ ] 6.9 Add validation to prevent exports of excessive date ranges

- [ ] 7.0 Integrate Initiative Data with Existing JIRA Sync Services
  - [ ] 7.1 Update `JiraWorklogIncrementalSyncService` to sync label changes and epic updates (PRD Req #3)
  - [ ] 7.2 Create initiative data recalculation job to run after JIRA sync completion (PRD Req #3)
  - [ ] 7.3 Add initiative-specific validation to ensure data consistency after sync operations (PRD Req #4)
  - [ ] 7.4 Update sync progress reporting to include initiative data updates
  - [ ] 7.5 Add caching layer for initiative calculations to improve dashboard performance
  - [ ] 7.6 Create background job for periodic initiative metric recalculation
  - [ ] 7.7 Add monitoring and alerting for initiative data inconsistencies
  - [ ] 7.8 Update existing admin sync interface to show initiative-related sync status
  - [ ] 7.9 Add initiative data to sync history and error reporting