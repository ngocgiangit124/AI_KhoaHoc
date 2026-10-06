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

];
