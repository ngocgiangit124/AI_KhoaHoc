<?php

/*
|--------------------------------------------------------------------------
| Gọi nội bộ từ Next.js SSR (throttle `catalog`)
|--------------------------------------------------------------------------
| SSR gọi API từ 1 IP server nên mọi người dùng dùng chung 1 bucket. Khi header `X-Internal-Token` khớp
| `INTERNAL_API_TOKEN`, limiter `catalog` tính theo IP khách thật trong `X-Client-IP`. Để trống = tắt.
*/

return [
    'ssr_token' => env('INTERNAL_API_TOKEN'),
    'ssr_token_min_length' => 32,
    // true: production bắt buộc có token (token rỗng → guard chặn khởi động).
    'required' => (bool) env('INTERNAL_API_REQUIRED', false),
    // Trần tổng cho mọi request mang token đúng (mỗi IP khách vẫn bị `catalog_per_minute`).
    'catalog_per_minute' => (int) env('CATALOG_LIMIT_PER_MINUTE', 120),
    'catalog_ssr_total_per_minute' => (int) env('CATALOG_SSR_TOTAL_PER_MINUTE', 6000),
];
