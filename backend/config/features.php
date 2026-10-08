<?php

/*
|--------------------------------------------------------------------------
| Feature flags (README §5, tasks.md T01)
|--------------------------------------------------------------------------
|
| Bật/tắt các nhánh nghiệp vụ còn chờ PO quyết định cuối cùng. Chỉ đọc qua
| config() — không hardcode chuỗi cấu hình trong code nghiệp vụ.
| `GET /api/v1/config/public` chỉ lộ ra allowlist khoá tường minh, không trả
| nguyên mảng này (S22).
|
*/

return [

    'referral_code' => (bool) env('FEATURE_REFERRAL_CODE', true),

    'quiz_time_limit' => (bool) env('FEATURE_QUIZ_TIME_LIMIT', true),

    'zero_total_checkout' => (bool) env('FEATURE_ZERO_TOTAL_CHECKOUT', true),

    'enrollment_decision_mail' => (bool) env('FEATURE_ENROLLMENT_DECISION_MAIL', false),

    'staff_mfa' => (bool) env('FEATURE_STAFF_MFA', true),

    // ADR-006 (T29): gửi thư THÔNG BÁO cho phụ huynh (tạo tài khoản, thêm/đổi email phụ huynh, đơn có tiền đã thanh toán).
    // Công tắc tắt khẩn: false → không gửi thư nào, các request gốc vẫn thành công. Mail thật phải bật trước go-live.
    'parent_notices' => (bool) env('FEATURE_PARENT_NOTICES', true),

    // Checkout có tính tiền (tổng > 0). PO 2026-10-06: thanh toán chuyển V2 (chờ kết nối MoMo) → mặc định TẮT.
    // Tắt: POST /checkout tổng > 0 trả 503 PAYMENT_DISABLED, preview có can_checkout=false. Đơn 0đ/khóa miễn phí không ảnh hưởng.
    'paid_checkout' => (bool) env('FEATURE_PAID_CHECKOUT', false),

    'external_video_preview_only' => (bool) env('FEATURE_EXTERNAL_VIDEO_PREVIEW_ONLY', true),

];
