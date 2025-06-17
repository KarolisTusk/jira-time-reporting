<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 512, // FIXED: Increased for large project syncs and 119k+ hours dataset

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    | PRD Requirements Alignment:
    | - High priority for sync operations (target: < 30 min for monthly sync)
    | - Automated daily sync processing
    | - Real-time progress tracking support
    |
    */

    'defaults' => [
        // High priority supervisor for JIRA sync operations (PRD: critical operations)
        'jira-sync-high' => [
            'connection' => 'redis',
            'queue' => ['jira-sync-high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 14400, // 4 hours max per job (FIXED: large project syncs)
            'maxJobs' => 10,
            'memory' => 512, // PRD: target < 512MB during sync operations
            'tries' => 3, // PRD: 95% error recovery target
            'timeout' => 14400, // 4 hours timeout (FIXED: prevent incomplete syncs)
            'nice' => 0, // Highest priority
        ],
        
        // Medium priority for daily automated syncs
        'jira-sync-daily' => [
            'connection' => 'redis',
            'queue' => ['jira-sync-daily'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 900, // 15 minutes for incremental daily syncs
            'maxJobs' => 50,
            'memory' => 256,
            'tries' => 5, // More retries for daily automation
            'timeout' => 900,
            'nice' => 5,
        ],

        // Dedicated queue for worklog incremental sync (lightweight operations)
        'jira-worklog-sync' => [
            'connection' => 'redis',
            'queue' => ['jira-worklog-sync'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 1800, // 30 minutes max (worklogs are faster than full sync)
            'maxJobs' => 100,
            'memory' => 256, // Lower memory needs for worklog-only operations
            'tries' => 3,
            'timeout' => 1800, // 30 minutes timeout
            'nice' => 3, // Higher priority than background tasks
        ],
        
        // Low priority for background tasks (reports, cleanup)
        'jira-background' => [
            'connection' => 'redis',
            'queue' => ['jira-background', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 600,
            'maxJobs' => 100,
            'memory' => 128,
            'tries' => 2,
            'timeout' => 300,
            'nice' => 10,
        ],
        
        // Real-time progress tracking (PRD: < 5 seconds update requirement)
        'jira-realtime' => [
            'connection' => 'redis',
            'queue' => ['jira-realtime'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 60,
            'maxJobs' => 1000,
            'memory' => 64,
            'tries' => 1, // Fast failure for real-time jobs
            'timeout' => 10, // Quick timeout for real-time operations
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'jira-sync-high' => [
                'maxProcesses' => 3, // Allow parallel processing for large syncs
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'jira-sync-daily' => [
                'maxProcesses' => 2, // Multiple daily syncs can run in parallel
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'jira-worklog-sync' => [
                'maxProcesses' => 3, // Allow multiple worklog syncs to run in parallel
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'jira-background' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 10,
            ],
            'jira-realtime' => [
                'maxProcesses' => 1, // Single process for real-time consistency
                'balanceMaxShift' => 0,
                'balanceCooldown' => 1,
            ],
        ],

        'local' => [
            'jira-sync-high' => [
                'maxProcesses' => 1,
            ],
            'jira-sync-daily' => [
                'maxProcesses' => 1,
            ],
            'jira-worklog-sync' => [
                'maxProcesses' => 1,
            ],
            'jira-background' => [
                'maxProcesses' => 1,
            ],
            'jira-realtime' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
