<?php

/*
|--------------------------------------------------------------------------
| VideoLab — nhà cung cấp video nội bộ (ADR-002, T12)
|--------------------------------------------------------------------------
|
| Module độc lập (app/VideoLab, routes/videolab.php, bảng vl_*). Chỉ đăng ký route khi `enabled`.
| Khoá (api_key/token_key/webhook_secret): ở local/testing tự suy ra từ APP_KEY nếu chưa đặt env, để chạy
| được ngay; mọi môi trường khác local/testing BẮT BUỘC đặt riêng, ≥ 32 ký tự (VideoLabServiceProvider chặn khởi động nếu thiếu).
|
*/

// Chỉ local/testing mới được suy khoá từ APP_KEY; mọi môi trường khác (staging, production...) phải đặt khoá riêng.
$derive = static fn (string $purpose): ?string => ! in_array(env('APP_ENV'), ['local', 'testing'], true)
    ? null
    : hash_hmac('sha256', 'videolab:'.$purpose, (string) env('APP_KEY', 'videolab-local'));

return [

    'enabled' => filter_var(env('VIDEOLAB_ENABLED', env('VIDEO_PROVIDER', 'internal') === 'internal'), FILTER_VALIDATE_BOOL),

    // Host công khai của VideoLab (chỉ /videolab/tus* và /videolab/cdn/* được mở ra Internet).
    'host' => env('VIDEOLAB_HOST', 'video.localhost'),

    // Adapter (app → VideoLab) gọi HTTP vào đây, kèm header Host = `host`. Docker: http://nginx.
    'internal_url' => rtrim((string) env('VIDEOLAB_INTERNAL_URL', 'http://nginx'), '/'),

    // URL trình duyệt dùng để upload TUS và phát HLS.
    'public_url' => rtrim((string) env('VIDEOLAB_PUBLIC_URL', 'http://video.localhost:8000'), '/'),

    // VideoLab → app (webhook xong/lỗi). Gọi qua Nginx nội bộ với Host của host api.
    'webhook_url' => (string) env('VIDEOLAB_WEBHOOK_URL', 'http://nginx/api/v1/webhooks/video/internal'),
    'webhook_host' => env('VIDEOLAB_WEBHOOK_HOST', env('APP_API_HOST', 'api.localhost')),

    'api_key' => env('VIDEOLAB_API_KEY') ?: $derive('api'),
    'token_key' => env('VIDEOLAB_TOKEN_KEY') ?: $derive('token'),
    'webhook_secret' => env('VIDEOLAB_WEBHOOK_SECRET') ?: $derive('webhook'),

    // Mỗi PATCH TUS tối đa ngần này MB (Nginx `client_max_body_size` + `post_max_size` phải lớn hơn).
    // Frontend đặt `chunkSize` của tus-js-client ≤ giá trị này.
    'chunk_max_mb' => (int) env('VIDEOLAB_CHUNK_MAX_MB', 8),
    // Giới hạn upload tối đa mỗi video = video.max_upload_mb (nguồn: config/video.php).

    // Tối đa hiệu lực chữ ký/phiên upload (ADR-002 §3a.5). Dư 5 phút để chữ ký cấp ngay sau createVideo không hụt.
    'upload_ttl_seconds' => 6 * 3600 + 300,

    'storage' => [
        'incoming_retention_hours' => 24,
        'source_retention_days' => 7,
        // Video lỗi (status 5): xoá file gốc sau khoảng này (giờ), kể cả khi keep_source bật.
        'failed_source_retention_hours' => 24,
        'keep_source' => filter_var(env('VIDEOLAB_KEEP_SOURCE', false), FILTER_VALIDATE_BOOL),
    ],

    'ffmpeg' => [
        'ffmpeg' => env('VIDEOLAB_FFMPEG', 'ffmpeg'),
        'ffprobe' => env('VIDEOLAB_FFPROBE', 'ffprobe'),
        'probe_timeout' => 60,
        // Mỗi độ phân giải một lần chạy ffmpeg; tổng phải < timeout job.
        'encode_timeout' => (int) env('VIDEOLAB_ENCODE_TIMEOUT', 1500),
        'threads' => (int) env('VIDEOLAB_FFMPEG_THREADS', 2),
        'max_duration_minutes' => (int) env('VIDEOLAB_MAX_DURATION_MINUTES', 180),
        'max_width' => 3840,
        'max_height' => 2160,
        // Từ chối nguồn nhỏ hơn mức này (cạnh ngắn) và tỉ lệ khung hình dài/ngắn vượt mức (0 = tắt).
        'min_dimension' => 100,
        'max_aspect_ratio' => 4,
        'segment_seconds' => 6,
        // height => [video_bitrate_k, audio_bitrate_k]
        'renditions' => [360 => [800, 96], 720 => [2800, 128]],
    ],

    'job' => [
        // Connection riêng: retry_after > timeout job (xem config/queue.php `redis_video`).
        'connection' => env('VIDEOLAB_QUEUE_CONNECTION', 'redis_video'),
        'queue' => 'video',
        'timeout' => 3600,
        'tries' => 2,
    ],

    // Dev: response()->file(). Có Nginx `location /_protected_hls/ { internal; }`: bật để dùng X-Accel-Redirect.
    'accel_redirect' => filter_var(env('VIDEOLAB_ACCEL_REDIRECT', false), FILTER_VALIDATE_BOOL),
    'accel_prefix' => '/_protected_hls',

];
