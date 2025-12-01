<?php

use Illuminate\Support\Facades\Storage;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Workspace Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | meng override filesystems.php
    |
    */
    'default' => env('DOCHUB_WORCKSPACE_DISK', 'local'),

    
    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => Storage::path("dochub/workspace"), // relative to filesystems.php disk
            // 'serve' => true,
            // 'throw' => false,
            // 'report' => false,
        ],

        // 's3' => [
        //     'driver' => 's3',
        //     'key' => env('AWS_DOCHUB_ACCESS_KEY_ID'),
        //     'secret' => env('AWS_DOCHUB_SECRET_ACCESS_KEY'),
        //     'region' => env('AWS_DOCHUB_DEFAULT_REGION'),
        //     'bucket' => env('AWS_DOCHUB_BUCKET'),
        //     'url' => env('AWS_DOCHUB_URL'),
        //     'endpoint' => env('AWS_DOCHUB_ENDPOINT'),
        //     'use_path_style_endpoint' => env('AWS_DOCHUB_USE_PATH_STYLE_ENDPOINT', false),
        //     'throw' => false,
        //     'report' => false,
        // ],

    ],
];