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

    // T30 — thời hạn lưu `audit_logs` (tháng). Log có IP/user-agent là dữ liệu cá nhân → không giữ vô thời hạn.
    // PO uỷ quyền chọn: 24 tháng (đủ cho điều tra gian lận/tranh chấp đơn; cần pháp chế xác nhận ở T29/T34).
    // L2: trigger MySQL `audit_logs_block_delete` ghi CỨNG 24 tháng (migration 2026_10_16_100000) và chặn xoá dòng mới hơn;
    // `audit:purge` từ chối giá trị < 24. Muốn đổi thời hạn lưu phải thêm migration đổi trigger.
    'audit_retention_months' => (int) env('OPS_AUDIT_RETENTION_MONTHS', 24),

    // H1: địa chỉ hỗ trợ ghi trong thư thông báo đổi email.
    'support_email' => env('SUPPORT_EMAIL', 'hotro@vitaminvui.vn'),
    // T30 — tài khoản học sinh chưa xác thực OTP quá số ngày này (và chưa có đơn/ghi danh/bài làm) bị xoá.
    'unverified_account_days' => (int) env('OPS_UNVERIFIED_ACCOUNT_DAYS', 7),
    // Kích thước lô + nghỉ giữa các lô (ms) cho các lệnh dọn dữ liệu.
    'purge_chunk' => (int) env('OPS_PURGE_CHUNK', 1000),
    'purge_sleep_ms' => (int) env('OPS_PURGE_SLEEP_MS', 100),
];
