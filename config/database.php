<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | The 'tenant' connection is the per-client database, resolved at runtime
    | by ValidateFacility middleware after the tenant is identified.
    | See: docs/TENANT_RESOLUTION.md
    |
    */

    'default' => env('DB_CONNECTION', 'tenant'),

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        /*
        |----------------------------------------------------------------------
        | admin
        |----------------------------------------------------------------------
        | Privileged connection used only during tenant provisioning
        | (OnboardTenant command + CreateTenantJob).
        | Not used at runtime - bound to a separate, least-privilege DB user.
        | No 'database' is set here; the provisioning job selects the target DB.
        */
        'admin' => [
            'driver'      => 'mysql',
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => null,
            'username'    => env('DB_ADMIN_USERNAME'),
            'password'    => env('DB_ADMIN_PASSWORD'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'strict'      => false,
        ],

        /*
        |----------------------------------------------------------------------
        | tenant
        |----------------------------------------------------------------------
        | Per-client isolated database. The 'database' key starts empty and is
        | set dynamically in FacilityService::configureTenant() after the
        | incoming request is resolved to a facility.
        |
        | All DB::connection('tenant') calls across services use this connection.
        | See: docs/TENANT_RESOLUTION.md
        */
        'tenant' => [
            'driver'         => 'mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => '',   // set at runtime by FacilityService::configureTenant()
            'username'       => env('DB_USERNAME'),
            'password'       => env('DB_PASSWORD'),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
        ],

        /*
        |----------------------------------------------------------------------
        | shared
        |----------------------------------------------------------------------
        | Read-only connection to the shared reference database:
        | countries, provinces, cities, facilities, designations, etc.
        | Shared across all tenants - never written to at runtime.
        */
        'shared' => [
            'driver'         => 'mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_SHARED_DATABASE', 'shared'),
            'username'       => env('DB_USERNAME'),
            'password'       => env('DB_PASSWORD'),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
        ],

    ],

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Three logical databases are used:
    |   DB 0 (default) - general-purpose tenant cache (facility info, lookups)
    |   DB 1 (cache)   - Laravel cache driver
    |   DB 2 (session) - session store (isolated from cache for easy flush)
    |
    | All Redis keys are tenant-namespaced at the application layer.
    | See: docs/TENANT_RESOLUTION.md for key naming conventions.
    |
    | Retry config: decorrelated jitter backoff prevents thundering-herd
    | on Redis restarts under concurrent multi-tenant load.
    */
    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster'    => env('REDIS_CLUSTER', 'redis'),
            'prefix'     => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'app')) . '-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'host'               => env('REDIS_HOST', '127.0.0.1'),
            'password'           => env('REDIS_PASSWORD'),
            'port'               => env('REDIS_PORT', '6379'),
            'database'           => env('REDIS_DB', '0'),
            'max_retries'        => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm'  => 'decorrelated_jitter',
            'backoff_base'       => 100,
            'backoff_cap'        => 1000,
        ],

        'session' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            'prefix'   => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'app')) . '_session_'),
        ],

        'cache' => [
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6379'),
            'database'          => env('REDIS_CACHE_DB', '1'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base'      => 100,
            'backoff_cap'       => 1000,
        ],

    ],

];