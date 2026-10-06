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
            // L1 (review bảo mật T01/T02) — TẮT: 'serve' => true đăng ký 2 route
            // KHÔNG có domain/middleware nào (GET ServeFile + PUT ReceiveFile, ghi
            // file vào storage/app/private), chữ ký không gắn host nên ký ở host
            // này dùng được ở host kia. Dự án không dùng route này: file xuất
            // (CSV/XLSX) dùng controller + signed URL riêng (S14); ảnh công khai
            // phục vụ từ tên miền tĩnh riêng (STATIC_URL), không qua route này.
            'serve' => false,
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

        // T08 (S2): ảnh người dùng tải lên đã được mã hoá lại (WebP, tên UUID). Phục vụ từ STATIC_URL (tên miền
        // tĩnh riêng, không cookie) — Nginx trỏ root vào thư mục này; Laravel không phục vụ qua route.
        'uploads' => [
            'driver' => 'local',
            'root' => env('UPLOADS_PATH', storage_path('app/uploads')),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // T12 — lưu trữ VideoLab (incoming/source/hls). Riêng, không public; worker video mount đúng thư mục này.
        'videolab' => [
            'driver' => 'local',
            'root' => env('VIDEOLAB_PATH', storage_path('app/videolab')),
            'visibility' => 'private',
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
