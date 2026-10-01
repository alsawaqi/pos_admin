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

        // Merchant compliance documents (CR, owner ID card, ...). Private by
        // design and never served by nginx. LAUNCH-P1 P1-1: the default is
        // the local driver under storage/app/private/documents, which lives
        // in the storage-data volume that ops/backup archives. S3 is opt-in
        // only (DOCUMENTS_DISK_DRIVER=s3 plus the DOCUMENTS_AWS_* values):
        // the S3 Flysystem adapter is not installed, so defaulting to it made
        // every upload crash with a 500.
        'documents' => [
            'driver' => env('DOCUMENTS_DISK_DRIVER') ?: 'local',
            'key' => env('DOCUMENTS_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('DOCUMENTS_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('DOCUMENTS_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('DOCUMENTS_AWS_BUCKET'),
            'url' => env('DOCUMENTS_AWS_URL'),
            'endpoint' => env('DOCUMENTS_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('DOCUMENTS_AWS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
            // An empty `DOCUMENTS_LOCAL_ROOT=` line must not turn into the
            // process working directory.
            'root' => env('DOCUMENTS_LOCAL_ROOT') ?: storage_path('app/private/documents'),
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
