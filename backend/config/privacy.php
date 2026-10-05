<?php

/*
|--------------------------------------------------------------------------
| Dữ liệu cá nhân (US-017/US-018 — S7, chờ pháp chế xác nhận nội dung)
|--------------------------------------------------------------------------
*/

return [

    // Phiên bản văn bản chính sách hiện hành, gắn vào mỗi bản ghi `consents`.
    'policy_version' => env('PRIVACY_POLICY_VERSION', '2026-09'),

    // Dưới ngưỡng tuổi này lúc đăng ký thì bắt buộc có liên hệ phụ huynh
    // và cần `parent_consent_status = granted` trước khi checkout/đăng ký học miễn phí.
    'parent_consent_age' => (int) env('PRIVACY_PARENT_CONSENT_AGE', 18),

    // Múi giờ dùng để tính tuổi lúc đăng ký (US-001: "theo ngày hiện tại của server").
    // app.timezone là UTC nên phải chỉ định riêng để sinh nhật không lệch 1 ngày.
    'age_timezone' => env('PRIVACY_AGE_TIMEZONE', 'Asia/Ho_Chi_Minh'),

];
