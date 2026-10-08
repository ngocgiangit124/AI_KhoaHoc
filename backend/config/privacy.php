<?php

/*
|--------------------------------------------------------------------------
| Dữ liệu cá nhân (US-017/US-018 — S7, chờ pháp chế xác nhận nội dung)
|--------------------------------------------------------------------------
*/

return [

    // Phiên bản văn bản chính sách hiện hành, gắn vào mỗi bản ghi `consents`.
    // ADR-006: bản TẠM, thay khi pháp chế gửi bản chính thức.
    'policy_version' => env('PRIVACY_POLICY_VERSION', '2026-10-tam'),

    // ADR-006: dưới tuổi này FE chỉ GỢI Ý nhập liên hệ phụ huynh (không bao giờ bắt buộc). Trả ra `/config/public`
    // dưới khoá deprecated `parent_consent_age`.
    'parent_contact_suggest_age' => (int) env('PRIVACY_PARENT_CONTACT_SUGGEST_AGE', 18),

    // T34: số lần tải dữ liệu cá nhân thành công mỗi ngày lịch (Asia/Ho_Chi_Minh).
    'data_export_daily_limit' => (int) env('PRIVACY_DATA_EXPORT_DAILY_LIMIT', 2),

    // Trần thư thông báo gửi tới MỘT địa chỉ phụ huynh mỗi ngày (tính trên mọi học sinh).
    'parent_notice_daily_cap_per_address' => (int) env('PRIVACY_PARENT_NOTICE_DAILY_CAP', 5),

    // Khoá HMAC của token huỷ nhận thông báo. Mặc định dẫn xuất từ APP_KEY với nhãn riêng (xem `ParentNoticeToken`).
    'notice_token_key' => env('PRIVACY_NOTICE_TOKEN_KEY'),

    // Múi giờ dùng để tính tuổi lúc đăng ký (US-001: "theo ngày hiện tại của server").
    // app.timezone là UTC nên phải chỉ định riêng để sinh nhật không lệch 1 ngày.
    'age_timezone' => env('PRIVACY_AGE_TIMEZONE', 'Asia/Ho_Chi_Minh'),

];
