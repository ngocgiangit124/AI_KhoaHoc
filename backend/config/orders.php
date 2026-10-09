<?php

/*
|--------------------------------------------------------------------------
| Đơn hàng (US-005, ADR-001 §7)
|--------------------------------------------------------------------------
*/

return [

    // Đơn pending quá thời hạn này tự huỷ (US-005 BR7) — `orders.expires_at = created_at + giá trị này`.
    'pending_ttl_hours' => (int) env('ORDERS_PENDING_TTL_HOURS', 12),

    // T16-1/T16-2: trần số lần nhập SAI mã giảm giá mỗi ngày theo IP (ngoài trần 30/ngày/HS), chống dò mã bằng nhiều
    // tài khoản. Lớp học dùng chung NAT vẫn đủ rộng; đặt 0 để tắt.
    'coupon_fails_per_ip_per_day' => (int) env('ORDERS_COUPON_FAILS_PER_IP_PER_DAY', 150),

    // US-022 (ADR-007, docs/tech/US-022.md): thanh toán thủ công "Liên hệ Quản trị viên". Mọi con số và kênh liên hệ là cấu hình.
    'manual' => [
        // Q3: hạn chờ duyệt của đơn `manual` (đồng thời là hạn giữ chỗ lượt mã giảm giá). Guard production 1..168.
        'pending_ttl_hours' => (int) env('ORDERS_MANUAL_PENDING_TTL_HOURS', 72),

        // Q11: duyệt muộn đơn đã huỷ trong N ngày kể từ lúc huỷ (T39). 0 = tắt. Guard production 0..90.
        'approval_window_days' => (int) env('ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 30),

        // Q13: số đơn `manual` MỚI tối đa mỗi ngày lịch (giờ VN) mỗi học sinh, tính cả đơn đã huỷ. Guard production 1..50.
        'per_day' => (int) env('ORDERS_MANUAL_PER_DAY', 5),

        // Nhãn "Sắp hết hạn" ở danh sách quản trị (AC15). Hằng, không có env.
        'expiring_soon_hours' => 12,

        // Q5: hộp thư nhận thông báo đơn mới (danh sách, phân tách bằng dấu phẩy). Để trống hoặc không đặt = dùng SUPPORT_EMAIL.
        'notify_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (env('ORDERS_MANUAL_NOTIFY_EMAILS') ?: env('SUPPORT_EMAIL', 'hotro@vitaminvui.vn')))
        ), fn (string $e) => $e !== '')),

        // Q1: kênh liên hệ công khai (qua /config/public). Kênh để trống = null = FE không hiển thị.
        'contact' => [
            'phone' => env('PAYMENT_CONTACT_PHONE') ?: null,
            'zalo_url' => env('PAYMENT_CONTACT_ZALO_URL') ?: null,
            'email' => env('PAYMENT_CONTACT_EMAIL', env('SUPPORT_EMAIL', 'hotro@vitaminvui.vn')) ?: null,
            'hours' => env('PAYMENT_CONTACT_HOURS') ?: null,
        ],

        // Q16: nhãn hiển thị (hằng tiếng Việt).
        'label' => 'Liên hệ Quản trị viên',
        'description' => 'Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn',
    ],

];
