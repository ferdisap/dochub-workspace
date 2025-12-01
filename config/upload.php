<?php

use Illuminate\Support\Facades\Storage;

return [
  /*
    |--------------------------------------------------------------------------
    | Default Upload Driver
    |--------------------------------------------------------------------------
    | Supported: "tus", "native", "auto"
    | Auto-detect by UploadServiceProvider jika null atau 'auto'
    */
  'default' => env('UPLOAD_DRIVER','auto'),

  /*
    |--------------------------------------------------------------------------
    | Default Cache store
    |--------------------------------------------------------------------------
    | jika pakai tus, bisa di ovveride manual pakai use_redis
    */
  'cache' => [
    "driver" => env('DOCHUB_CACHE_STORE', 'file'),
    "ttl" => env('UPLOAD_CACHE_TTL', 86400), // 24 jam
    "prefix" => env('TUS_CACHE_PREFIX', 'dhub:'),
    // 'scan_count' => env('UPLOAD_CACHE_SCAN_COUNT', 100),
  ],

  /*
    |--------------------------------------------------------------------------
    | Environment-Based Strategy
    |--------------------------------------------------------------------------
    */
  'strategy' => [
    'shared' => 'native',
    'serverless' => 'native',
    'container' => 'tus',
    'dedicated' => 'tus',
  ],

  /*
    |--------------------------------------------------------------------------
    | Global Limits
    |--------------------------------------------------------------------------
    */
  'max_size' => env('UPLOAD_MAX_SIZE', 10 * 1024 * 1024 * 1024), // 10 GB, maximum size upload akan di overtake jika lebih besar dari envorment size
  'chunk_size' => env('UPLOAD_CHUNK_SIZE', 1 * 1024 * 1024), // 1 MB
  'timeout' => env('UPLOAD_TIMEOUT', 300), // job queue timeout
  'expiration' => env('UPLOAD_EXPIRATION', 604800), // 7 hari

  /*
    |--------------------------------------------------------------------------
    | Driver Configurations
    |--------------------------------------------------------------------------
    */
  'driver' => [
    'tus' => [
      'root' => Storage::path('dochub/tusupload'),
      'production_safe' => env('TUS_PRODUCTION_SAFE', env('APP_ENV') === 'production'),
      'cache_ttl' => env('TUS_CACHE_TTL', 86400), // 24 jam
      'cache_prefix' => env('TUS_CACHE_PREFIX', 'tus:'),
      'use_redis' => env('TUS_USE_REDIS', false),
      'lock_timeout' => env('TUS_LOCK_TIMEOUT', 30), // detik
    ],
    'native' => [
      'root' => Storage::path('dochub/upload'),
      'temp_prefix' => 'native_',
      'cleanup_after' => env('NATIVE_CLEANUP_AFTER', 3600), // 1 jam
    ],
  ],

  /*
    |--------------------------------------------------------------------------
    | Redis Configuration (Shared)
    |--------------------------------------------------------------------------
    */
  'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'), // atau phpredis
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', 6379),
    'database' => env('REDIS_DB', 1), // bukan 0 agar DB terpisah untuk upload
    'password' => env('REDIS_PASSWORD'),
    'connection_timeout' => 5.0,
    'read_write_timeout' => 3.0,
    'scan_count' => env('UPLOAD_REDIS_SCAN_COUNT', 100),

    // 'url' => env('REDIS_URL'),
    // 'host' => env('REDIS_HOST', '127.0.0.1'),
    // 'port' => env('REDIS_PORT', '6379'),
    // 'database' => env('REDIS_DB', '0'),
    // 'max_retries' => env('REDIS_MAX_RETRIES', 3),
    // 'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
    // 'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
    // 'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
  ],

  /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    */
  'cleanup' => [
    'enabled' => env('UPLOAD_CLEANUP_ENABLED', true),
    'interval' => env('UPLOAD_CLEANUP_INTERVAL', 3600), // 1 jam
    'batch_size' => env('UPLOAD_CLEANUP_BATCH', 100),
  ],
];
