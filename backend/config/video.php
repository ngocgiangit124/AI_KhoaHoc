<?php

/*
|--------------------------------------------------------------------------
| Video (ADR-002, T11)
|--------------------------------------------------------------------------
|
| `provider` là nhà cung cấp mặc định; `enabled_providers` là allowlist (webhook/manager chỉ nhận tên trong
| danh sách). `fake` chỉ được đăng ký ở local/testing (VideoServiceProvider) và ProductionConfigGuard cấm ở
| production. `internal` (VideoLab, T12) và `bunny` chưa có adapter: chọn chúng khi chưa có adapter sẽ trả 503
| VIDEO_PROVIDER_UNAVAILABLE.
|
*/

return [

    'provider' => env('VIDEO_PROVIDER', 'internal'),

    'enabled_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VIDEO_ENABLED_PROVIDERS', (string) env('VIDEO_PROVIDER', 'internal')))
    ))),

    'library_id' => env('VIDEO_LIBRARY_ID', 'default'),

    // Kích thước tối đa mỗi video tải lên (MB) và hạn mức mỗi người tạo mỗi ngày (GB) — ADR-002 §3a.5.
    'max_upload_mb' => (int) env('VIDEO_MAX_UPLOAD_MB', 2048),
    'daily_quota_gb' => (int) env('VIDEO_DAILY_QUOTA_GB', 20),

    // Chỉ để báo lỗi sớm cho người dùng; kiểm định dạng thật bằng magic bytes ở VideoLab (T12).
    'allowed_extensions' => ['mp4', 'mov', 'mkv', 'webm'],

    // Hiệu lực chữ ký upload (≤ 6 giờ — ADR-002 §3a.5). `min(360, …)` CỐ Ý cắt giá trị env lớn hơn 360.
    'upload_ttl_minutes' => min(360, (int) env('VIDEO_UPLOAD_TTL_MINUTES', 360)),

    // Dùng ở T13 (playback).
    'playback_ttl_minutes' => (int) env('VIDEO_PLAYBACK_TTL_MINUTES', 15),
    'bind_ip' => (bool) env('VIDEO_BIND_IP', true),

    // `videos:check-stuck`: chỉ đồng bộ asset chưa xong sau `sync_after_minutes`; đánh dấu failed khi quá hạn.
    'stuck' => [
        'sync_after_minutes' => 15,
        // Tính từ lúc tạo phiên upload: hạn ký (6h) + 1h dung sai.
        'upload_timeout_minutes' => (int) env('VIDEO_UPLOAD_TIMEOUT_MINUTES', 420),
        // Tính từ lần đổi trạng thái gần nhất (processing).
        'processing_timeout_minutes' => (int) env('VIDEO_PROCESSING_TIMEOUT_MINUTES', 240),
    ],

    // `videos:prune-orphans`: chỉ xoá asset không còn bài trỏ tới, tạo cách đây ít nhất ngần này phút.
    'orphan_grace_minutes' => (int) env('VIDEO_ORPHAN_GRACE_MINUTES', 10),

    'providers' => [
        'fake' => [
            'secret' => env('FAKE_VIDEO_SECRET', 'fake-video-local-only'),
            'tus_endpoint' => env('FAKE_VIDEO_TUS_ENDPOINT', 'https://fake-video.vitaminvui.test/tus'),
            'cdn_base' => env('FAKE_VIDEO_CDN_BASE', 'https://fake-video.vitaminvui.test/cdn'),
        ],
    ],

];
