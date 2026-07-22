<?php

declare(strict_types=1);

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

        /*
         * Private document storage — the disk every uploaded file goes to.
         *
         * Spec section 6, "File storage": binary content never goes in the
         * database, and uploads never go to a web-served disk. Staff
         * certificates carry personal data — full names, national ID numbers,
         * dates of birth — and the 'public' disk above is symlinked into
         * public/storage, which would give every one of those files a
         * permanent URL that needs no login and cannot be recalled once it
         * leaks.
         *
         * Three properties make that impossible here, and StaffCertificateTest
         * asserts each of them:
         *
         *   - 'root' is under storage/, outside public_path(), so the web
         *     server never maps a URL onto these bytes directly.
         *   - No 'url' key, so Storage::disk('private')->url() throws rather
         *     than inventing a public address, and this disk is absent from
         *     the 'links' array below — nothing symlinks it into public/.
         *   - 'serve' is false, so Laravel registers no route for it (see
         *     Illuminate\Filesystem\FilesystemServiceProvider::serveFiles).
         *
         * Files here are served only through a policy-authorized download,
         * which checks authorization per request. That route is built in the
         * task that adds the staff profile UI, not here.
         *
         * That task should NOT reach for Storage::disk('private')->url(). The
         * local driver has no URL to give, so it falls back to returning
         * '/storage/<path>' — which happens to be the route the framework's
         * default 'local' disk registers over this same directory, and which
         * refuses any request without a valid signature (403). The string looks
         * like a working link and is not one. Build the controller route and
         * authorize through StaffCertificatePolicy::view() instead.
         *
         * Phase 2 receipts and phase 3 student certificates reuse this disk.
         * They do not reuse the staff_certificates model — each file kind gets
         * its own model, policy, and retention rules.
         */
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'serve' => false,
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
