<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiter Store (P3.5-T16)
    |--------------------------------------------------------------------------
    |
    | THE LIMITER DOES NOT RUN ON THE DATABASE STORE, AND THE REASON IS MEASURED.
    |
    | Laravel binds the RateLimiter to this store rather than the default one
    | (CacheServiceProvider), and leaving it unset makes it inherit `default`,
    | which here is `database`. That is what T11 measured failing: two HTTP 500s
    | in 240 receipt downloads, both MySQL 1213 deadlocks inside
    | Illuminate\Cache\RateLimiter.
    |
    | InnoDB's own report, read from the Linux target, names the cycle: two
    | concurrent `insert ignore into cache` statements for one key each take a
    | shared record lock during the duplicate-key check and then each needs an
    | exclusive one on that same record — "locks rec but not gap" on all four
    | sides, so no gap lock is involved and a different isolation level would
    | not help. The record they collide on is delete-marked, left by the expiry
    | DELETE that `DatabaseStore::many()` issues during a READ: every
    | `RateLimiter::attempts()` call can therefore write, and at the decay
    | boundary one request deletes while others insert. Reproduced in the
    | container at 63 deadlocks in 182,327 attempts.
    |
    | Redis is chosen because its INCR is atomic, which is what a limiter needs,
    | and because production already requires Redis for queues (design §11), so
    | this adds no infrastructure. The `file` store was rejected on correctness,
    | not taste: `FileStore::increment()` is a read-modify-write with no lock, so
    | concurrent hits lose increments and the throttle admits more than it says.
    |
    | §11 records the narrowing: this moves the LIMITER only. The application
    | cache and sessions stay database-backed, which is the decision §11 makes
    | and this does not reopen.
    |
    | Tests pin this to `array` in phpunit.xml, beside the `CACHE_STORE` pin, so
    | the suite needs no Redis.
    |
    */

    'limiter' => env('CACHE_LIMITER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "storage", "octane",
    |                    "session", "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'storage' => [
            'driver' => 'storage',
            'disk' => env('CACHE_STORAGE_DISK'),
            'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    'serializable_classes' => false,

];
