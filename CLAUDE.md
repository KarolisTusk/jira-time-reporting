# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a JIRA Time Consumption Reporting application built with Laravel 12 + Vue 3 + Inertia.js + PostgreSQL. The application connects to JIRA instances to import project data, issues, and worklogs for comprehensive time tracking analysis and reporting.

**Key Features:**
- **Enhanced JIRA Synchronization System**: Incremental sync with real-time progress tracking
- **JIRA Initiatives Feature**: Client-specific worklog reporting across projects, labels, and epics
- **Resource Type Classification**: Automatic worklog categorization (frontend, backend, QA, DevOps, management)
- **Excel Export Capabilities**: Multi-sheet exports with detailed worklog breakdowns

## Tech Stack
- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: Vue 3 + TypeScript + Inertia.js
- **Styling**: Tailwind CSS 4.x + Reka UI components
- **Database**: Neon PostgreSQL (managed cloud database) 
- **Queue**: Laravel Horizon 5.33+ (Redis-based background job processing)
- **Testing**: Pest PHP + Vitest (JavaScript)
- **JIRA Integration**: lesstif/php-jira-rest-client
- **Excel Export**: maatwebsite/excel 3.1+
- **Log Viewer**: Laravel Pail 1.2+

## Common Development Commands

```bash
# Development (runs all services concurrently)
composer dev              # Runs server, queue, logs, and vite concurrently
composer dev:ssr          # Development with SSR support

# Individual services
php artisan serve          # Laravel server
npm run dev               # Vite dev server (creates public/hot for Laravel integration)
php artisan queue:listen  # Queue worker
php artisan pail          # Logs

# Build & Assets
npm run build             # Production build
npm run build:ssr         # SSR build

# Code Quality
npm run lint              # ESLint with auto-fix
npm run format            # Prettier formatting
npm run format:check      # Check formatting
php artisan pint          # Laravel Pint (PHP CS Fixer)
vue-tsc --noEmit          # TypeScript type checking

# Testing
composer test             # Run all tests (clears config first)
php artisan test --filter=TestName  # Run specific test
php artisan test --parallel         # Run tests in parallel
npm run test              # Run Vitest tests
npm run test:ui           # Vitest UI mode
npm run test:run          # Run tests once

# Database
php artisan migrate       # Run migrations
php artisan migrate:fresh # Fresh migration

# Queue Management
php artisan horizon       # Start Horizon queue processing
php artisan queue:work --queue=jira-sync-high,jira-sync-daily,jira-worklog-sync,jira-background,default --tries=3 --timeout=14400  # Production queue worker
php artisan queue:monitor jira-worklog-sync  # Monitor specific queue
```

## JIRA Sync System Commands

The application includes a comprehensive Enhanced JIRA Synchronization System with specialized commands:

```bash
# Core JIRA Sync Operations
php artisan jira:daily-sync            # Automated daily sync for all projects
php artisan jira:daily-sync --projects=DEMO,TEST  # Sync specific projects
php artisan jira:daily-sync --force    # Force sync ignoring business hours
php artisan jira:daily-sync --dry-run  # Preview sync without execution

# Incremental Worklog Sync Operations
php artisan jira:sync-worklogs         # Sync worklogs from last 24 hours
php artisan jira:sync-worklogs --projects=DEMO,TEST  # Sync specific projects
php artisan jira:sync-worklogs --hours=8   # Sync worklogs from last 8 hours
php artisan jira:sync-worklogs --force     # Force sync all worklogs
php artisan jira:sync-worklogs --async     # Run as background job
php artisan jira:sync-worklogs --status    # Show worklog sync status

# Worklog Sync Validation and Monitoring
php artisan jira:worklog-validation    # View validation report for all projects
php artisan jira:worklog-validation --projects=DEMO,TEST  # Specific projects
php artisan jira:worklog-validation --detailed  # Show detailed validation results
php artisan jira:worklog-validation --summary   # Show only summary statistics
php artisan jira:worklog-validation --export=csv  # Export results to CSV/JSON

# Sync Monitoring & Debugging
php artisan jira:sync:monitor          # Monitor sync jobs and history
php artisan jira:sync-debug            # Debug sync processes and recovery
php artisan jira:cleanup-stuck-syncs   # Clean stuck sync operations
php artisan jira:cleanup-stuck-syncs --force  # Force cleanup all active syncs

# Cache Management (Performance Optimization)
php artisan jira:cache:warm --all --stats     # Warm cache with statistics
php artisan jira:cache:warm --projects=DEMO,TEST  # Warm specific projects
php artisan jira:cache:manage stats           # Show cache statistics
php artisan jira:cache:manage clear --force   # Clear all cache

# API Response Cache Management 
php artisan jira:api-cache:manage stats       # API response cache stats
php artisan jira:api-cache:manage clear --project=DEMO  # Clear project API cache
php artisan jira:api-cache:manage warm --project=DEMO   # Warm API cache

# Database Performance (PRD Compliance)
php artisan db:connections:manage stats       # Connection pool statistics
php artisan db:connections:manage optimize    # Optimize connections
php artisan db:replicas:manage health         # Check replica health
php artisan db:replicas:manage test-performance --project=DEMO  # Test performance

# Testing & Development
php artisan jira:test-app              # Test app functionality
php artisan jira:test-data             # Generate test data for development

# Initiative Management Commands
php artisan initiative:calculate INITIATIVE_ID  # Calculate hours/costs for specific initiative
php artisan initiative:export INITIATIVE_ID     # Generate Excel export for initiative
php artisan initiative:validate                 # Validate all initiative configurations
```

## Architecture Overview

### Enhanced JIRA Synchronization System
The core feature is a sophisticated JIRA sync system with:

- **Incremental Sync**: Only syncs data updated since last successful sync
- **Incremental Worklog Sync**: Lightweight worklog-only sync for daily maintenance
- **Automated Scheduling**: Daily worklog sync at 9 AM and 5 PM, plus optional business hour syncs
- **Checkpoint Recovery**: Automatic recovery from partial failures
- **Resource Type Classification**: Categorizes worklogs (frontend, backend, QA, DevOps, management, etc.)
- **Real-time Progress Tracking**: Live progress updates with WebSocket broadcasting
- **Conflict Resolution**: JIRA as source of truth with comprehensive conflict handling
- **Performance Optimization**: Database indexes, query optimization, and API response caching

### Key Services

#### JIRA Synchronization Services
- **`EnhancedJiraImportService`**: Core sync logic with incremental sync and resource classification
  - Handles worklog comment format (string vs JIRA rich text array)
  - JIRA as source of truth conflict resolution
  - Resource type detection with priority-based keyword matching
  - Enhanced to collect labels and epic data for initiatives
- **`JiraWorklogIncrementalSyncService`**: Lightweight worklog-only incremental sync
  - Syncs only worklogs added/updated since last sync
  - Resource type classification and conflict resolution
  - Background job processing with automated scheduling
- **`JiraWorklogSyncValidationService`**: Data quality validation for worklog sync
  - Sample-based validation against JIRA API
  - Resource type distribution analysis
  - Data integrity checks and completeness scoring
- **`JiraSyncCheckpointService`**: Checkpoint management for sync recovery
- **`JiraSyncProgressService`**: Real-time progress tracking and broadcasting
- **`JiraSyncCacheService`**: Intelligent caching for API responses and worklog data
- **`JiraApiServiceV3`**: Optimized JIRA REST API v3 integration with rate limiting
  - Conservative 10 req/sec, 3 concurrent requests
  - Batch size optimization (50 items optimal)
  - Enhanced fields: `summary,status,assignee,created,updated,worklog,labels,parent`

#### Initiative Services
- **`JiraInitiativeService`**: Core business logic for initiative calculations
  - Hours/cost calculation with date range filtering
  - Monthly breakdown analysis for trend reporting
  - Contributing issues analysis with resource type breakdown
  - Excel export data preparation with multi-sheet structure
- **`InitiativeWorklogExport`**: Laravel Excel export implementation
  - Summary sheet with totals and monthly breakdown
  - Monthly detail sheet with granular worklog data
  - Contributing issues sheet with issue-level analysis
  - Cost visibility controls based on user permissions

### Database Schema (Key Tables)
- **`jira_sync_histories`**: Tracks all sync operations with progress and error details
- **`jira_sync_checkpoints`**: Checkpoint data for recovery from partial failures
- **`jira_project_sync_statuses`**: Per-project sync status with timestamps
- **`jira_worklog_sync_statuses`**: Per-project worklog sync status and statistics
- **`jira_worklogs`**: Enhanced with `resource_type` column for work categorization
- **`jira_issues`**: JIRA issues with comprehensive field mapping including `labels` and `epic_key`
- **`jira_projects`**: JIRA projects with sync status tracking
- **`initiatives`**: Client-specific initiative definitions with hourly rates
- **`initiative_project_filters`**: Flexible filtering rules combining projects, labels, and epics
- **`initiative_access`**: Role-based access control for client users

### Frontend Architecture
- **Vue 3 + TypeScript**: Modern reactive frontend with strict typing
- **Inertia.js**: SPA experience without API complexity
- **Component Library**: Reka UI components with Tailwind CSS
- **Admin Interface**: `/admin/jira/sync` - Enhanced sync controls with real-time progress
- **Issues Browser**: `/admin/jira/issues` - Comprehensive issue browsing and analysis
- **Initiatives Management**: `/admin/initiatives` - Initiative CRUD with project filter configuration
- **Client Dashboard**: `/initiatives` - Client-facing initiative overview with metrics and export

### Queue System (Critical for Sync Operations)
- **Laravel Horizon**: Redis-based queue management with monitoring
- **`ProcessEnhancedJiraSync`**: Background job for full sync operations
- **`ProcessJiraWorklogIncrementalSync`**: Background job for worklog-only sync
- **Queue Names**: 
  - `jira-sync-high` (high priority full sync operations)
  - `jira-sync-daily` (automated daily syncs)
  - `jira-worklog-sync` (dedicated worklog incremental sync)
  - `jira-background` (background processing tasks)
  - `default` (general Laravel jobs)
- **Retry Logic**: Exponential backoff with intelligent rate limiting
- **Automated Scheduling**: Laravel task scheduler for daily worklog maintenance

## Development Workflow

### JIRA Sync Development
1. **Always run queue worker** during sync development: `php artisan queue:listen`
2. **Monitor sync progress** via admin interface: `/admin/jira/sync`
3. **Use debug commands** for troubleshooting: `php artisan jira:sync-debug`
4. **Check for stuck syncs**: `php artisan jira:cleanup-stuck-syncs`

### Testing JIRA Functionality
1. **Configure JIRA settings**: Go to `/settings/jira` first
2. **Generate test data**: `php artisan jira:test-data` for development
3. **Test sync operations**: Use admin interface or daily sync command
4. **Monitor performance**: Use cache and database monitoring commands

### Initiative Development
1. **Use factories for testing**: `Initiative::factory()` and `InitiativeProjectFilter::factory()`
2. **Test with real data**: Run sync first, then create initiatives to see actual calculations
3. **Admin interface**: `/admin/initiatives` for CRUD operations and configuration
4. **Client testing**: Create test users and assign initiative access via `initiative_access` table
5. **Export testing**: Use `/initiatives/{id}/export` endpoint or Excel download button

### Frontend Development
- **Development server**: `npm run dev` creates `public/hot` file for Laravel integration
- **Component changes**: Hot module replacement works when development server is running
- **Path alias**: `@/*` maps to `./resources/js/*`
- **TypeScript**: Strict mode enabled with Vue JSX support

## Critical Notes

### Queue Worker is Essential
The JIRA sync system relies heavily on background job processing. **Always ensure a queue worker is running** when developing or testing sync functionality:
```bash
# Development (uses queue:listen for better debugging)
php artisan queue:listen --queue=jira-sync-high,jira-sync-daily,jira-worklog-sync,jira-background,default --tries=3 --timeout=300

# Production (uses queue:work for better performance, timeout 4 hours for large syncs)
php artisan queue:work --queue=jira-sync-high,jira-sync-daily,jira-worklog-sync,jira-background,default --tries=3 --timeout=14400

# Or use Laravel Horizon for full queue management
php artisan horizon
```

### Initiative Configuration Requirements
Before initiatives can show data:
1. **JIRA Sync Required**: Run full sync to populate `jira_issues` with labels and epic data
2. **Project Filters**: Each initiative needs at least one project filter combining project + labels + epic
3. **Access Control**: Client users need records in `initiative_access` table to view initiatives
4. **Hourly Rates**: Set appropriate hourly rates for cost calculations

### Common Troubleshooting Issues

#### Sync Operations Getting Stuck
If sync operations get stuck at a specific issue count:
1. Check logs: `php artisan pail`
2. Look for type errors (e.g., `strtolower(): Argument #1 ($string) must be of type string, array given`)
3. Clean stuck syncs: `php artisan jira:cleanup-stuck-syncs --force`
4. Check worklog comment format issues (JIRA rich text vs plain text)

#### Frontend Changes Not Taking Effect
If component changes don't appear in browser:
1. Ensure Vite dev server is running: `npm run dev`
2. Verify `public/hot` file exists (indicates dev server active)
3. Check for component naming conflicts (multiple components with same name)
4. Clear browser cache and hard refresh

#### Validation Errors in Sync Interface
Recent fixes handle new sync types:
- `force_full` sync type now supported in validation
- Custom date range functionality simplified
- Validation accepts: `['incremental', 'last7days', 'last30days', 'custom', 'force_full']`

### Development vs Production Assets
- **Development**: `npm run dev` → Creates `public/hot` → Laravel uses Vite dev server
- **Production**: `npm run build` → Creates `public/build/` → Laravel uses production assets
- **Hot reload only works** when `public/hot` file exists (development server running)

### Database Performance
- Application is optimized for large datasets (119k+ worklog hours baseline)
- Uses PostgreSQL 14 with performance indexes for sync operations
- Read replica support available for query performance optimization

### JIRA API Rate Limiting
- Conservative rate limiting: 10 requests/second, 3 concurrent requests
- Intelligent batch sizing: 50 items optimal for JIRA API v3
- Exponential backoff retry mechanism for failed requests

## File Locations
- **Backend**: `/app/` (Laravel structure)
- **Frontend**: `/resources/js/` (Vue components, pages, layouts)
- **Tests**: `/tests/` (Pest PHP tests)
- **Database**: `/database/` (migrations, factories, seeders)
- **Documentation**: `docs/` (comprehensive setup and troubleshooting guides)
  - `docs/INDEX.md` - Complete documentation index
  - `docs/VERSION_HISTORY.md` - Detailed version history
  - `docs/troubleshooting/` - Troubleshooting guides
  - `docs/setup/` - Setup guides for PostgreSQL, queues, etc.
  - `docs/api/` - API documentation
- **Tasks**: `/tasks/` (AI-assisted development tracking with .mdc files)
- **AI Development Rules**: `/docs/rules/` (Cursor IDE integration files)

## Recent Major Features (June 2025)

### JIRA Initiatives Feature (Version 7.0)
- **Client-Specific Reporting**: Flexible worklog grouping across projects, labels, and epics
- **Role-Based Access Control**: Client users see only assigned initiatives, admins see all
- **Cost Transparency**: Configurable hourly rates with cost visibility controls  
- **Excel Export**: Multi-sheet exports with summary, monthly breakdown, and contributing issues
- **Initiative Dashboard**: Real-time metrics with hours, costs, and trend analysis
- **Flexible Filtering**: Complex project + label + epic combinations for precise targeting

### Enhanced Database Schema
- **Labels & Epic Support**: Extended `jira_issues` table with `labels` (JSON) and `epic_key` columns
- **Initiative Tables**: `initiatives`, `initiative_project_filters`, `initiative_access`
- **PostgreSQL Constraints**: Database-level validation with SQLite compatibility for testing

### Critical Fixes
- **Worklog Comment Format**: Fixed handling of JIRA rich text comments (array vs string)  
- **Sync Interface Validation**: Added support for `force_full` sync type
- **Custom Date Range**: Simplified interface to resolve user interaction issues
- **Laravel-Vite Integration**: Fixed development server detection via `public/hot` file
- **Resource Type Classification**: Enhanced keyword matching with priority-based detection
- **SQLite Compatibility**: Database-agnostic migrations for development testing

## Docker Deployment Optimizations

### **Optimized Docker Images**
The application includes optimized Docker configurations for efficient deployment:

#### **Dockerfile.digitalocean** (Production-Ready)
- **Multi-stage build**: Separate frontend and backend building
- **Optimized dependencies**: Runtime vs build dependencies properly separated
- **Reduced image size**: ~50-100MB smaller through dependency optimization
- **Security improvements**: Fewer packages = reduced attack surface

#### **Key Optimizations Applied:**
- **Removed unused runtime dependencies**: `redis` (server), `git`, `zip/unzip`
- **Build dependency management**: Virtual packages for clean removal
- **PHP extension optimization**: All extensions built in single layer
- **Health check**: Uses standard Laravel endpoint (`/`)

#### **Dependencies Organization:**
```dockerfile
# Runtime only (kept in final image)
RUN apk add nginx supervisor curl bash libpng libjpeg freetype icu libzip

# Build dependencies (removed after use)
RUN apk add --virtual .build-deps autoconf gcc g++ make postgresql-dev...
```

### **Performance Benefits**
- ⚡ **Faster deployments**: Smaller images transfer quicker
- 🔒 **Enhanced security**: Minimal attack surface
- 💾 **Reduced storage**: ~20% smaller Docker images
- 🚀 **Better caching**: Optimized layer structure

## Version Info
- **Current**: v7.0.0 - JIRA Initiatives Feature + Docker Optimizations (June 2025)
- **Package Version**: v7.0.0 (synchronized with release version)

For detailed version history and features, see `docs/VERSION_HISTORY.md`.
For comprehensive troubleshooting findings, see `docs/troubleshooting/troubleshooting-findings.md`.
For complete documentation index, see `docs/INDEX.md`.