<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    | ── WHY THE FALLBACK IS `database` AND NOT `sync` ────────────────────────
    | `sync` executes a "queued" job inside the request that dispatched it. Here
    | that meant GenerateScreenshotThumbnail's GD decode/resize ran inside the
    | desktop agent's screenshot upload request: measured on PHP 8.3 + GD at
    | 39 ms / 28 MB for 1080p, 63 ms / 54 MB for 1440p, 144 ms / 104 MB for 4K
    | and 320 ms / 208 MB for a dual-4K capture — once per capture per person.
    | At 100 people per tenant across hundreds of tenants that is FPM workers
    | and RAM taken from every other tenant's page loads, for work nobody is
    | waiting on.
    |
    | The `database` driver needs no new infrastructure (the `jobs` table ships
    | with this release) and App\Console\Kernel schedules a
    | `queue:work --stop-when-empty` drain every minute, so jobs are still
    | processed on a box with no supervised worker. A box that DOES run a
    | supervised worker simply finds the queue already empty.
    |
    | Set QUEUE_CONNECTION=sync explicitly to go back. phpunit.xml does exactly
    | that, so the test suite still runs jobs inline and deterministically.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            // Above the slowest measured job (320 ms for a dual-4K thumbnail)
            // by three orders of magnitude, so a job is never handed to a second
            // worker while the first is still legitimately working on it.
            'retry_after' => 90,
            // Jobs dispatched inside a transaction are only pushed once it
            // commits. Without this a worker can pick up a job referring to a
            // row the dispatching transaction has not written yet (or rolls
            // back), which reads as an intermittent "record not found".
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
