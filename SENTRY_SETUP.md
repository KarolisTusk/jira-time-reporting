# Sentry Source Maps Setup Guide

## Overview
Sentry source maps are configured and ready for upload. The wizard encountered TTY issues, but the configuration has been manually completed.

## Configuration Status
✅ **Frontend Integration**: Vue.js with Sentry SDK installed and configured
✅ **Backend Integration**: Laravel with exception and database monitoring  
✅ **Source Maps Generation**: Vite configured to generate source maps
✅ **Upload Plugin**: @sentry/vite-plugin installed and configured

## Required Configuration

### 1. Get Sentry Organization & Project Information
1. Log into your Sentry dashboard at https://sentry.io
2. Navigate to Settings → Projects
3. Note your organization slug and project slug

### 2. Generate Auth Token
1. Go to https://sentry.io/settings/auth-tokens/
2. Click "Create New Token"
3. Select scopes: `project:read`, `project:releases`, `org:read`
4. Copy the generated token

### 3. Update Environment Variables

#### Local Development (.env)
```bash
SENTRY_ORG=your-actual-org-slug
SENTRY_PROJECT=your-actual-project-slug  
SENTRY_AUTH_TOKEN=your-actual-auth-token
```

#### Production (DigitalOcean App Platform)
Update the environment variables in `.do/app.yaml` or the DigitalOcean dashboard:
- `SENTRY_ORG`: Your organization slug
- `SENTRY_PROJECT`: Your project slug
- `SENTRY_AUTH_TOKEN`: Your auth token (encrypted recommended)

### 4. Update Configuration Files

#### sentry.properties
```
defaults.url=https://sentry.io/
defaults.org=your-actual-org-slug
defaults.project=your-actual-project-slug
auth.token=your-actual-auth-token
```

## Current DSNs
- **Backend (Laravel)**: `https://6201d48e2849126b0c9e1f5db42d4396@o4509526861676544.ingest.us.sentry.io/4509526863577088`
- **Frontend (Vue.js)**: `https://59d8da25303031f0ec664fe407c593e3@o4509526861676544.ingest.us.sentry.io/4509526931013632`

## Testing Source Maps
Once configured, run:
```bash
npm run build
```

You should see:
- Source maps generated (`.map` files)
- Sentry plugin analyzing sources
- Successful upload message (when auth token is configured)

## Features Enabled
- **Error Tracking**: Frontend + Backend + Database
- **Performance Monitoring**: Request traces, database queries
- **Session Replay**: Visual debugging for frontend issues
- **Source Maps**: Precise error locations in original code (once configured)
- **PII Collection**: IP addresses and user data for enhanced context

## Troubleshooting
- Ensure auth token has correct scopes
- Verify organization and project slugs are exact matches
- Check that build process has access to environment variables
- Source maps will only upload in production builds