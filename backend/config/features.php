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

    // Chặn checkout với HS chưa có xác nhận phụ huynh (US-017). PO tạm bỏ ngưỡng tuổi ở v1 → mặc định TẮT;
    // T29 bật khi có luồng đồng ý + nội dung pháp lý.
    'parent_consent_enforced' => (bool) env('FEATURE_PARENT_CONSENT_ENFORCED', false),

    'external_video_preview_only' => (bool) env('FEATURE_EXTERNAL_VIDEO_PREVIEW_ONLY', true),

];
