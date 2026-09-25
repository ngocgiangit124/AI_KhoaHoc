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

];
