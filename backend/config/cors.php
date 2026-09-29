<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS — ADR-004 §2.3
    |--------------------------------------------------------------------------
    |
    | `allowed_origins` không đặt tĩnh ở đây: middleware toàn cục
    | ConfigureHostContext set config('cors.allowed_origins') runtime theo host
    | (host api ← FRONTEND_URL; host admin-api ← ADMIN_URL) trước khi HandleCors
    | chạy — luôn đúng 1 origin, không wildcard.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'X-CSRF-TOKEN',
        'X-Requested-With',
        'X-Device-Id',
        'X-Request-Id',
    ],

    // T04 security review L3 — `Retry-After` (429 do throttle route lẫn
    // DomainException `TOO_MANY_ATTEMPTS` từ tầng Service) phải được trình
    // duyệt ĐỌC ĐƯỢC qua fetch()/XHR (mặc định CORS chỉ cho JS đọc các header
    // "an toàn" liệt kê sẵn, không có `Retry-After`). Không rò rỉ gì nhạy cảm:
    // mọi khoá đều theo `user_id`/IP của chính người gọi request đó.
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];
