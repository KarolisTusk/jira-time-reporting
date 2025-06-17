# JIRA Time Consumption Reporting - Core Implementation Tasks

## Overview
This document tracks the foundational implementation tasks for the core JIRA Time Consumption Reporting system. This provides the base functionality that was later enhanced by the Enhanced JIRA Synchronization System.

## Related Documentation
- **Enhanced System PRD**: [`prd-enhanced-jira-sync.md`](./prd-enhanced-jira-sync.md) - Advanced synchronization requirements
- **Enhanced Tasks**: [`tasks-prd-enhanced-jira-sync.md`](./tasks-prd-enhanced-jira-sync.md) - Advanced implementation tasks
- **Legacy Enhancements**: [`tasks-jira-sync-enhancements.md`](./tasks-jira-sync-enhancements.md) - Intermediate sync improvements

> **Implementation Scope**: This document covers the core JIRA integration, basic data models, API services, and fundamental reporting capabilities. The Enhanced JIRA Synchronization System (see related PRD) builds upon this foundation with advanced features like real-time progress tracking, resource classification, and performance optimization.

## Relevant Files

- `app/Models/JiraSetting.php` - Model to store JIRA API token and configured project keys.
- `database/migrations/YYYY_MM_DD_HHMMSS_create_jira_settings_table.php` - Migration for the `jira_settings` table.
- `app/Models/JiraProject.php` - Model for locally stored JIRA project information.
- `database/migrations/YYYY_MM_DD_HHMMSS_create_jira_projects_table.php` - Migration for the `jira_projects` table.
- `app/Models/JiraIssue.php` - Model for locally stored JIRA issue details.
- `database/migrations/YYYY_MM_DD_HHMMSS_create_jira_issues_table.php` - Migration for the `jira_issues` table.
- `app/Models/JiraWorklog.php` - Model for locally stored JIRA worklog entries.
- `database/migrations/YYYY_MM_DD_HHMMSS_create_jira_worklogs_table.php` - Migration for the `jira_worklogs` table.
- `app/Models/JiraAppUser.php` - Model to store JIRA user information (displayName, accountId) related to worklogs/assignees.
- `database/migrations/YYYY_MM_DD_HHMMSS_create_jira_app_users_table.php` - Migration for the `jira_app_users` table.
- `app/Services/JiraApiService.php` - Service class to encapsulate JIRA API interactions (using a PHP JIRA client library).
- `app/Services/JiraImportService.php` - Service class to handle the logic of fetching data from JIRA and storing it locally.
- `app/Http/Controllers/JiraSettingsController.php` - Controller to handle saving and retrieving JIRA connection settings.
- `app/Http/Controllers/JiraImportController.php` - Controller to trigger the manual JIRA data import process.
- `app/Http/Controllers/ReportController.php` - Controller to fetch data and prepare it for various reports.
- `app/Console/Commands/ImportJiraDataCommand.php` - (Alternative to Controller) Artisan command for manual or potentially scheduled JIRA data import.
- `routes/web.php` - To define routes for settings pages, import triggers, and report views.
- `routes/api.php` - (Optional) If report data is fetched asynchronously by Vue components.
- `resources/js/Pages/Settings/Jira.vue` - Vue component for the JIRA settings page (API token, project keys) - implemented with sync button.
- `resources/js/Pages/Reports/ProjectTime.vue` - Vue component to display the "Total time per project" report - implemented with BarChart.
- `resources/js/Pages/Reports/UserTimePerProject.vue` - Vue component to display the "Total time per user on a project" report - implemented with BarChart.
- `resources/js/Pages/Reports/ProjectTrend.vue` - Vue component to display the "Project time trend" report - implemented with LineChart.
- `resources/js/Components/Layouts/AuthenticatedLayout.vue` (or similar existing layout) - To add navigation links.
- `resources/js/Components/Charts/BarChart.vue` - Reusable Vue component for bar charts (implemented).
- `resources/js/Components/Charts/LineChart.vue` - Reusable Vue component for line charts (implemented).
- `resources/js/Composables/useJira.js` - (Optional) Vue composable for JIRA related frontend logic.
- `tests/Feature/JiraSettingsTest.php` - Feature tests for JIRA settings CRUD and connection test.
- `tests/Feature/JiraImportTest.php` - Feature tests for the JIRA data import process.
- `tests/Feature/ReportGenerationTest.php` - Feature tests for report data accuracy and filtering.
- `tests/Unit/JiraApiServiceTest.php` - Unit tests for the `JiraApiService` (mocking the actual API calls).
- `tests/Unit/JiraImportServiceTest.php` - Unit tests for the `JiraImportService` data transformation logic.
- `composer.json` - PHP dependency management file, updated with the JIRA API client library.
- `composer.lock` - Records the exact versions of PHP dependencies, updated.

### Notes

- Unit tests should typically be placed alongside the code files they are testing or in the corresponding `tests/Unit` or `tests/Feature` directories.
- Use `php artisan test` or `php artisan test --filter=TestName` to run tests.
- Remember to install a PHP JIRA API client library via Composer (e.g., `composer require lesstif/php-jira-rest-client` or another suitable one).
- Choose a Vue.js charting library (e.g., `vue-chartjs`, `apexcharts` with its Vue wrapper) and install it via npm/yarn.

## Tasks

- [x] 1.0 Setup Core Laravel Backend for JIRA Integration
  - [x] 1.1 Install a PHP JIRA API client library (e.g., `lesstif/php-jira-rest-client` or similar via Composer).
  - [x] 1.2 Define Eloquent models: `JiraSetting`, `JiraProject`, `JiraIssue`, `JiraWorklog`, `JiraAppUser`.
  - [x] 1.3 Create database migrations for the models defined in 1.2.
  - [x] 1.4 Run initial migrations: `php artisan migrate`.
  - [x] 1.5 Create `JiraApiService` class structure with placeholder methods for JIRA interactions (e.g., `getProjects`, `getIssuesForProject`, `getWorklogsForIssue`).
  - [x] 1.6 Configure the chosen JIRA API client library within `JiraApiService` or via a service provider.

- [x] 2.0 Implement JIRA Configuration Management
  - [x] 2.1 Create `JiraSettingsController` with methods for showing the settings form and storing settings.
  - [x] 2.2 Define routes in `routes/web.php` for the JIRA settings page (GET) and for saving settings (POST/PUT).
  - [x] 2.3 Implement logic in `JiraSettingsController` to securely store/update JIRA API token (encrypted) and project keys in the `jira_settings` table.
  - [x] 2.4 Add a method in `JiraApiService` or `JiraSettingsController` to test the connection to JIRA using the stored credentials (e.g., fetch basic user info or server info).
  - [x] 2.5 Create `tests/Feature/JiraSettingsTest.php` to test saving settings and the connection test.

- [x] 3.0 Develop JIRA Data Import Functionality
  - [x] 3.1 Create `JiraImportService` with methods to orchestrate the data import process.
  - [x] 3.2 Implement methods in `JiraApiService` to fetch:
    - [x] 3.2.1 Ticket details (Issue Key, Summary, Status, Assignee) for specified projects.
    - [x] 3.2.2 Worklog entries (User, Date, Time Spent) for fetched issues.
  - [x] 3.3 Implement logic in `JiraImportService` to:
    - [x] 3.3.1 Iterate through configured JIRA projects.
    - [x] 3.3.2 Fetch issues and their worklogs using `JiraApiService`.
    - [x] 3.3.3 Transform fetched JIRA data into the structure of local Eloquent models.
    - [x] 3.3.4 Store/update `JiraProject`, `JiraIssue`, `JiraAppUser` (for assignees/worklog authors), and `JiraWorklog` records in the database. Handle potential duplicates (e.g., update existing).
  - [x] 3.4 Create `JiraImportController` with a method to trigger the import process via `JiraImportService`.
  - [x] 3.5 Define a route in `routes/web.php` to trigger the manual import (e.g., POST to `/jira/import`).
  - [x] 3.6 Implement basic error handling and logging for the import process (e.g., API errors, data validation issues).
  - [x] 3.7 Create `tests/Unit/JiraImportServiceTest.php` for data transformation logic.
  - [x] 3.8 Create `tests/Feature/JiraImportTest.php` to test the end-to-end import trigger.

- [x] 4.0 Build Reporting and Visualization Features
  - [x] 4.1 Develop Eloquent model scopes or repository methods to query data for reports:
    - [x] 4.1.1 Total time spent per project (filterable by date range).
    - [x] 4.1.2 Total time spent by each user on a specific project (filterable by project and date range).
    - [x] 4.1.3 Trend of total time spent per project over time (weekly/monthly, filterable by project(s) and date range).
  - [x] 4.2 Create `ReportController` with methods to:
    - [x] 4.2.1 Handle incoming report requests with filters.
    - [x] 4.2.2 Fetch and process data using the queries from 4.1.
    - [x] 4.2.3 Prepare data in a format suitable for charting libraries and frontend display.
  - [x] 4.3 Define routes in `routes/web.php` (or `routes/api.php` if data is fetched async) for accessing report data.
  - [x] 4.4 Install a Vue.js charting library (e.g., `vue-chartjs` or `vue-apexcharts`).
  - [x] 4.5 Create reusable Vue components for charts (e.g., `BarChart.vue`, `LineChart.vue`).
  - [x] 4.6 Create `tests/Feature/ReportGenerationTest.php` to verify report data accuracy based on sample imported data.

- [x] 5.0 Develop User Interface for Settings and Reports
  - [x] 5.1 Create the Vue component `resources/js/Pages/Settings/Jira.vue`:
    - [x] 5.1.1 Form to input JIRA API Token and Project Keys.
    - [x] 5.1.2 Button to save settings, calling the route from 2.2.
    - [x] 5.1.3 Button to test JIRA connection, calling functionality from 2.4.
    - [x] 5.1.4 Button to trigger manual JIRA data sync, calling the route from 3.5. Display feedback (success/error).
    - [x] 5.2 Create the Vue component `resources/js/Pages/Reports/ProjectTime.vue`:
    - [x] 5.2.1 Date range filter.
    - [x] 5.2.2 Display total time per project using the bar chart component.
    - [x] 5.2.3 (Optional) Display data in a table format.
  - [x] 5.3 Create the Vue component `resources/js/Pages/Reports/UserTimePerProject.vue`:
    - [x] 5.3.1 Project selector filter.
    - [x] 5.3.2 Date range filter.
    - [x] 5.3.3 Display total time per user for the selected project using a bar chart or table.
  - [x] 5.4 Create the Vue component `resources/js/Pages/Reports/ProjectTrend.vue`:
    - [x] 5.4.1 Project selector filter (multi-select or single).
    - [x] 5.4.2 Date range filter.
    - [x] 5.4.3 Aggregation period filter (e.g., weekly, monthly).
    - [x] 5.4.4 Display project time trends using the line chart component.
  - [x] 5.5 Update `AuthenticatedLayout.vue` (or your main layout component) to include navigation links to the Settings page and the different Report pages.
  - [x] 5.6 Ensure Vue components fetch data from the `ReportController` (either on page load via Inertia props or via async API calls).
  - [x] 5.7 Style the UI for a clean and professional look, ensuring basic responsiveness.
