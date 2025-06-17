# Queue Setup for JIRA Sync Enhancement

## Overview

The JIRA sync functionality has been enhanced to use Laravel's queue system for better performance, real-time progress tracking, and reliability. This document explains how to set up and configure the queue system.

## Queue Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# Queue Configuration
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_TABLE=jobs
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90

# Redis Configuration (optional, for better performance)
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1
# REDIS_PASSWORD=null
# REDIS_PORT=6379
# REDIS_QUEUE_CONNECTION=default
# REDIS_QUEUE=default
# REDIS_QUEUE_RETRY_AFTER=90

# Broadcasting Configuration (for real-time updates)
BROADCAST_DRIVER=log
# For development with Laravel Echo
# BROADCAST_DRIVER=pusher
# VITE_PUSHER_APP_KEY=local-app-key
# VITE_PUSHER_HOST=127.0.0.1
# VITE_PUSHER_PORT=6001
# VITE_PUSHER_SCHEME=http
# VITE_PUSHER_APP_CLUSTER=mt1

# For production, consider using pusher or reverb
# BROADCAST_DRIVER=pusher
# PUSHER_APP_ID=your_app_id
# PUSHER_APP_KEY=your_app_key
# PUSHER_APP_SECRET=your_app_secret
# PUSHER_APP_CLUSTER=mt1
```

### Database Setup

The following tables are required and should already exist:
- `jobs` - Queue jobs
- `job_batches` - Job batching (for future enhancements)
- `failed_jobs` - Failed job tracking
- `jira_sync_histories` - Sync history records
- `jira_sync_logs` - Detailed sync logs

If any tables are missing, run:
```bash
php artisan migrate
```

## Real-time Broadcasting Setup

### Laravel Echo Configuration

Laravel Echo is configured in `resources/js/echo.ts` and automatically initialized in `app.ts`. The configuration supports:

- **Development**: Local WebSocket server (using Laravel Reverb or Pusher)
- **Production**: Pusher, Ably, or Redis broadcasting

### Broadcasting Drivers

#### Log Driver (Development)
```env
BROADCAST_DRIVER=log
```
- Events logged to Laravel logs
- No real-time updates
- Good for debugging events

#### Pusher Driver (Production)
```env
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=mt1
```

#### Reverb Driver (Self-hosted WebSockets)
```env
BROADCAST_DRIVER=reverb
REVERB_APP_ID=app-id
REVERB_APP_KEY=app-key
REVERB_APP_SECRET=app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Install and start Laravel Reverb:
```bash
php artisan reverb:install
php artisan reverb:start
```

### Frontend Configuration

Environment variables for Vite:
```env
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

## Running Queue Workers

### Development

For development, you can use:
```bash
# Basic queue worker
php artisan queue:work

# Queue worker with specific settings
php artisan queue:work --sleep=3 --tries=3 --max-time=3600

# For debugging, use sync driver (no queue)
# Set QUEUE_CONNECTION=sync in .env
```

### Production

For production, use a process manager like Supervisor:

1. Install Supervisor:
```bash
sudo apt-get install supervisor
```

2. Create Supervisor configuration file `/etc/supervisor/conf.d/jira-reporter-worker.conf`:
```ini
[program:jira-reporter-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/jira-reporter/artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/jira-reporter/storage/logs/worker.log
stopwaitsecs=3600
```

3. Update Supervisor and start workers:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start jira-reporter-worker:*
```

### Queue Worker Commands

```bash
# Start queue worker
php artisan queue:work

# Start queue worker on specific connection
php artisan queue:work database

# Start queue worker for specific queue
php artisan queue:work database --queue=default,high

# Restart all queue workers (after code deployment)
php artisan queue:restart

# Check queue status
php artisan queue:monitor

# Process failed jobs
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
```

## Monitoring

### Queue Status

Monitor your queues using:
```bash
# View all jobs in queue
php artisan queue:monitor

# View failed jobs
php artisan queue:failed

# View queue statistics
php artisan horizon:status  # If using Laravel Horizon

# Monitor JIRA sync jobs specifically
php artisan jira:sync:monitor status
php artisan jira:sync:monitor failed
```

### Sync History

Monitor JIRA sync operations through:
- Database: `jira_sync_histories` and `jira_sync_logs` tables
- Web UI: JIRA Settings page will show real-time progress
- Logs: Laravel logs in `storage/logs/`
- Real-time: WebSocket/Broadcasting updates

## Performance Considerations

### Database Driver
- **Pros**: Simple setup, no additional dependencies
- **Cons**: Can be slower for high-volume jobs
- **Best for**: Development, small-scale deployments

### Redis Driver
- **Pros**: Faster, better for high-volume jobs, supports advanced features
- **Cons**: Requires Redis server setup
- **Best for**: Production, high-volume sync operations

To switch to Redis:
1. Install Redis server
2. Install predis/predis composer package: `composer require predis/predis`
3. Update .env: `QUEUE_CONNECTION=redis`
4. Configure Redis connection in config/database.php

### Broadcasting Performance
- **Log**: No overhead, but no real-time updates
- **Pusher**: Reliable, scales well, but requires subscription
- **Reverb**: Self-hosted, free, but requires server resources
- **Redis**: Fast, but requires Redis setup

## Troubleshooting

### Common Issues

1. **Jobs not processing**:
   - Check if queue worker is running: `ps aux | grep "queue:work"`
   - Check queue connection in .env
   - Verify database connection

2. **Jobs failing silently**:
   - Check failed_jobs table
   - Check Laravel logs
   - Increase memory limit for workers

3. **Progress updates not showing**:
   - Check broadcasting configuration
   - Verify WebSocket/SSE setup
   - Check browser console for errors
   - Ensure broadcasting routes are loaded

4. **Broadcasting not working**:
   - Check if BroadcastServiceProvider is registered
   - Verify channel authorization in routes/channels.php
   - Check Laravel Echo configuration
   - Inspect network tab for WebSocket connections

### Debugging

```bash
# Run queue worker with verbose output
php artisan queue:work --verbose

# Run specific job
php artisan queue:work --once

# Clear failed jobs
php artisan queue:flush

# Retry specific failed job
php artisan queue:retry {job-id}

# Test broadcasting
php artisan tinker
>>> broadcast(new App\Events\JiraSyncProgress(...));

# Monitor JIRA sync operations
php artisan jira:sync:monitor status --hours=1
```

## Security Considerations

1. **Queue Worker Access**: Ensure queue workers run with appropriate permissions
2. **Database Security**: Secure database access credentials
3. **Redis Security**: If using Redis, secure with passwords and firewalls
4. **Broadcasting Security**: Use private channels with proper authorization
5. **WebSocket Security**: Secure WebSocket endpoints and use HTTPS in production
6. **Log Security**: Rotate and secure log files containing sensitive data

## Future Enhancements

- **Job Batching**: Group related sync operations
- **Job Priorities**: Prioritize certain sync operations
- **Horizon Integration**: Advanced queue monitoring and management
- **Scheduled Syncs**: Automatic periodic JIRA syncing
- **Multi-tenant Support**: User-specific queue isolation
- **Webhook Integration**: Real-time JIRA updates via webhooks
- **Advanced Broadcasting**: Multiple channels, channel groups, presence channels 