<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    'alert_email' => env('HORIZON_ALERT_EMAIL'),

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
    | Because the default derives from APP_NAME, renaming the application
    | changes Horizon's Redis namespace. Set a stable HORIZON_PREFIX in
    | deployed environments before changing APP_NAME.
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

    'silenced_tags' => [
        // 'notifications',
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

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,

            /*
             * NINETY-SIX, BECAUSE 128 HERE COULD NEVER FIRE AGAINST PHP'S 128
             * (P3.5-T17)
             * ---------------------------------------------------------------
             * This was 128, the same number as PHP's default `memory_limit`,
             * and that coincidence disabled the recycle entirely. Laravel
             * checks this ceiling AFTER a job returns (`Worker::runJob()` then
             * `stopIfNecessary()`), so a graceful restart requires a worker to
             * FINISH a job already holding 128 MiB — which the identical PHP
             * limit makes impossible. The allocation that would cross the line
             * throws `Allowed memory size of 134217728 bytes exhausted` inside
             * the render instead, and the check is never reached.
             *
             * That is what T11 and T13 both logged during the receipt drain,
             * in mPDF's TTFontFile.php. Every PDF still arrived, because the
             * job retries — so the cost was wasted renders and noise, not lost
             * documents.
             *
             * MEASURED, NOT ESTIMATED. Twenty-five receipts rendered back to
             * back in one process: the worker starts near 42 MiB, the first
             * render adds 22 MiB as mPDF loads and subsets its fonts, and
             * every render after that adds about 2 MiB which is never
             * released. At render 25 the process held 114 MiB and was still
             * climbing linearly, so exhaustion arrives near render 32 — about
             * what a 2,770-receipt drain over three workers would hit roughly
             * thirty times each.
             *
             * SO THE RENDER'S COST IS NOT THE DEFECT; THE UNREACHABLE CEILING
             * IS — AND EITHER SIDE OF THE PAIR CAN MOVE TO FIX IT. An earlier
             * version of this comment said raising PHP's limit alone would only
             * postpone a worker's death. That was wrong, and the review caught
             * it: with PHP above 128 a job could FINISH past 128, and the
             * post-job check would then see the ceiling exceeded and recycle
             * the worker. Raising PHP's limit is a real alternative fix, not a
             * delay.
             *
             * LOWERING IS CHOSEN FOR TWO REASONS, BOTH TRADE-OFFS RATHER THAN
             * NECESSITIES. This repository configures Horizon but not the
             * production `php.ini`, so a fix that depends on raising
             * `memory_limit` is not enforceable from here — it would be an
             * instruction to a deployment rather than a change. And a worker
             * recycled at 96 MiB holds flat memory, where a higher PHP limit
             * buys longer worker lives at the cost of more resident memory per
             * process, multiplied by ten in production.
             *
             * 96 leaves 32 MiB beneath PHP's default. The probe recorded
             * `memory_get_peak_usage(true)` beside the allocation after each
             * render, and the two were equal at every sample — so no render's
             * transient rose above where it ended, and the 22 MiB cold start
             * bounds the dearest single render, at the real allocator's ~2 MiB
             * resolution. A worker at the ceiling can therefore finish the job
             * in hand and be recycled before the next. Roughly seventeen
             * receipts per worker lifetime.
             *
             * THE INVARIANT, NOT THE NUMBER, IS WHAT MATTERS: this must stay
             * below the `memory_limit` the workers actually run under, with
             * room for one more render. `ReceiptGenerationTest` asserts it.
             * `maxJobs` was the alternative and is deliberately left at 0 — a
             * job count would hardcode today's per-render cost, while this
             * adapts if the render gets cheaper or dearer.
             *
             * Measured on Windows PHP 8.4. The Linux per-render peak and the
             * same-load drain are recorded with the rerun in the baseline
             * report.
             */
            'memory' => 96,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
