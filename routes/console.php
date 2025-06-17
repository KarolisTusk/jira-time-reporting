<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule daily JIRA sync to run every day at 2 AM
Schedule::command('jira:daily-sync')
    ->dailyAt('02:00')
    ->withoutOverlapping(120) // Prevent overlapping for up to 2 hours
    ->runInBackground()
    ->emailOutputOnFailure(config('mail.admin_email'))
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Scheduled daily JIRA sync failed');
    })
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('Scheduled daily JIRA sync completed successfully');
    });

// Schedule incremental worklog sync to run multiple times per day
Schedule::command('jira:sync-worklogs --hours=24 --async')
    ->dailyAt('09:00') // Morning sync - catch overnight worklogs
    ->withoutOverlapping(30) // Prevent overlapping for up to 30 minutes
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning('Scheduled morning worklog sync failed');
    });

Schedule::command('jira:sync-worklogs --hours=8 --async')
    ->dailyAt('17:00') // Evening sync - catch daily worklogs
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::warning('Scheduled evening worklog sync failed');
    });

// Optional: More frequent worklog sync during business hours (Mon-Fri)
Schedule::command('jira:sync-worklogs --hours=4 --async')
    ->twiceDaily(12, 15) // 12 PM and 3 PM
    ->weekdays()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->when(function () {
        // Only run if it's a business day and JIRA settings allow frequent sync
        return config('jira.enable_frequent_worklog_sync', false);
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::info('Scheduled frequent worklog sync failed (non-critical)');
    });
