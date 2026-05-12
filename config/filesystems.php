<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

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
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------
        | Off-site backup disk (S3-compatible)
        |----------------------------------------------------------------
        |
        | Used by `php artisan db:backup --disk=backup` to stream the
        | nightly dump off the application server. Works with any
        | S3-compatible bucket: Backblaze B2, Hetzner Object Storage,
        | Wasabi, Cloudflare R2, AWS S3.
        |
        | Activation: set BACKUP_DISK_BUCKET + BACKUP_DISK_KEY +
        | BACKUP_DISK_SECRET + BACKUP_DISK_REGION + BACKUP_DISK_ENDPOINT
        | in .env, then set DB_BACKUP_DISK=backup so the scheduler
        | starts writing there. Until then the disk is declared but
        | unused, so dev + test stay unchanged.
        |
        */
        'backup' => [
            'driver' => 's3',
            'key' => env('BACKUP_DISK_KEY'),
            'secret' => env('BACKUP_DISK_SECRET'),
            'region' => env('BACKUP_DISK_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_DISK_BUCKET'),
            'endpoint' => env('BACKUP_DISK_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_DISK_USE_PATH_STYLE', true),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
