<?php

/*
|--------------------------------------------------------------------------
| Vận hành queue/scheduler (T26)
|--------------------------------------------------------------------------
| Tên queue: `default` (mail, đồng bộ video, job nhẹ) và `exports` (xuất file lớn — job nặng, timeout dài,
| worker riêng được). Job/Mailable mới PHẢI khai báo `$tries`, `$timeout`, `$backoff`; `$timeout` luôn
| nhỏ hơn `REDIS_QUEUE_RETRY_AFTER` (90s) để job không bị phát lại khi còn đang chạy.
*/

return [
    'queues' => [
        'default' => 'default',
        'exports' => 'exports',
    ],

    'health' => [
        // Worker/scheduler im lặng quá số giây này → coi là chết.
        'worker_max_age' => (int) env('OPS_WORKER_MAX_AGE', 120),
        'scheduler_max_age' => (int) env('OPS_SCHEDULER_MAX_AGE', 180),
        // Số job lỗi tồn đọng / số job chờ vượt ngưỡng → cảnh báo.
        'failed_jobs_max' => (int) env('OPS_FAILED_JOBS_MAX', 10),
        'queue_backlog_max' => (int) env('OPS_QUEUE_BACKLOG_MAX', 500),
        'cache_prefix' => 'ops:heartbeat:',
    ],

    // Giữ failed_jobs bao lâu (giờ) trước khi `queue:prune-failed` xoá.
    'failed_jobs_retention_hours' => (int) env('OPS_FAILED_JOBS_RETENTION_HOURS', 720),
    // Giữ otp_codes bao lâu (ngày); limiter OTP chỉ nhìn cửa sổ 24h.
    'otp_retention_days' => (int) env('OPS_OTP_RETENTION_DAYS', 7),
];
