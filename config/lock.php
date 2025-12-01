<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lock Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "flock", "redis", "null"
    |
    | flock   : File-based lock (single server)
    | redis   : Distributed lock (cluster)
    | null    : No locking (testing only)
    |
    */
    'driver' => env('DOCHUB_WORCKSPACE_LOCK_DRIVER', 'flock'),

    /*
    |--------------------------------------------------------------------------
    | Lock Defaults
    |--------------------------------------------------------------------------
    */
    'default_timeout_ms' => env('DOCHUB_WORCKSPACE_LOCK_TIMEOUT_MS', 30000),
];