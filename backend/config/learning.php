<?php

/*
|--------------------------------------------------------------------------
| Học & tiến độ (T13, ADR-002 §5)
|--------------------------------------------------------------------------
*/

return [

    // Xem đạt tỷ lệ này của thời lượng thì bài tự hoàn thành (US-006 BR2).
    'complete_ratio' => 0.9,

    // Heartbeat: khoảng cách mặc định của lần đầu (giây), tốc độ phát tối đa tính được (2x), phần bù trễ mạng.
    'heartbeat' => [
        'first_interval_seconds' => 20,
        'max_speed' => 2,
        'slack_seconds' => 5,
        // Heartbeat đến sớm hơn mức này (giây) so với lần trước: không được cộng phần bù; cùng vị trí = gửi lại.
        'min_interval_seconds' => 5,
        'replay_window_seconds' => 3,
        // Trần theo NGƯỜI HỌC (mọi bài cộng dồn) trong cửa sổ `user_credit_window_seconds` giây, chặn mở N bài song song
        // để xong cả khóa trong 1 bài. `user_credit_cap_seconds` = null: tự tính `max_speed * (window + first_interval_seconds) + slack` (165 giây/60 giây: cửa sổ cố định neo ở lần cộng đầu nên
        // cần dư thêm 1 nhịp heartbeat, nếu không người xem 2x liên tục mất vài % số giây)
        // để người xem tốc độ tối đa cho phép (2x) vẫn được cộng đủ; đặt số cụ thể để ghi đè, 0 = tắt.
        'user_credit_cap_seconds' => null,
        'user_credit_window_seconds' => 60,
        // `enrollments.last_accessed_at` chỉ ghi tối đa 1 lần/khoảng này (phút).
        'enrollment_touch_minutes' => 5,
    ],

    // Cảnh báo cấp link bất thường (ADR-002 §4): quá ngưỡng trong 1 giờ thì ghi log warning.
    'playback_anomaly' => [
        'window_minutes' => 60,
        'max_ips' => 3,
        'max_lessons' => 60,
    ],

    // T23: Khóa học của tôi. Ghi log thời gian xử lý (channel `learning`); vượt `slow_ms` thì mức warning (mốc p95 300 ms).
    'my_courses' => [
        'per_page' => 12,
        'max_per_page' => 30,
        'slow_ms' => 300,
        // Số khóa đang chờ duyệt / bị từ chối trả kèm (không phân trang).
        'status_list_limit' => 20,
    ],

];
