<?php

use App\Support\ProductionConfigGuard;

/**
 * T04 (S9, S11), sửa theo security review L2 — `ProductionConfigGuard`:
 * `guardOtpChannels()` (allowlist), `guardOtpMailer()`, `guardOtpThresholds()`.
 * Cùng khuôn với `tests/Feature/T01/ProductionConfigGuardTest.php`: gọi
 * `check()` trực tiếp sau khi ép environment/config.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'session.secure' => true,
        'captcha.driver' => 'turnstile',
        'services.turnstile.secret' => 'test-secret',
        'services.turnstile.site_key' => 'test-site-key',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'],
        'app.trusted_proxies' => '10.0.0.1,10.0.0.2',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
        'auth.otp.channels' => ['email'],
        // phpunit.xml ép MAIL_MAILER=array cho testing — baseline hợp lệ ở
        // đây phải ghi đè sang 1 driver thật để không tự trượt guardOtpMailer().
        'mail.default' => 'smtp',
        'auth.otp.cooldown_seconds' => 60,
        'auth.otp.max_attempts_per_code' => 5,
        'auth.otp.max_per_day' => 10,
        'auth.otp.max_verify_per_day' => 20,
        'auth.otp.ttl_minutes' => 10,
    ]);
});

test('AUTH_OTP_CHANNELS=email hop le o production khong nem loi', function () {
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('AUTH_OTP_CHANNELS chua sms o production nem loi (S9, S11)', function () {
    config(['auth.otp.channels' => ['email', 'sms']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('AUTH_OTP_CHANNELS thieu email o production nem loi', function () {
    config(['auth.otp.channels' => []]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

/**
 * T04 security review L2 — TRƯỚC ĐÂY blocklist chỉ cấm đúng chuỗi `sms`, các
 * biến thể khác (`SMS` viết hoa, kênh lạ `push`) đều lọt. Allowlist mới so
 * khớp CHÍNH XÁC (phân biệt hoa/thường — cùng kiểu với `guardCaptcha()`,
 * cũng từ chối `Turnstile` viết hoa thay vì tự chuẩn hoá).
 */
test('AUTH_OTP_CHANNELS bien the sai chinh ta/hoa thuong/kenh la deu nem loi o production (L2)', function (array $channels) {
    config(['auth.otp.channels' => $channels]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
})->with([
    [['email', 'SMS']],
    [['email', 'push']],
    [['Email']],
]);

test('MAIL_MAILER=log o production nem loi (mo ta OTP se bi ghi ro vao log — S21, L2)', function () {
    config(['mail.default' => 'log']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('MAIL_MAILER=array o production nem loi (L2)', function () {
    config(['mail.default' => 'array']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('AUTH_OTP_COOLDOWN_SECONDS qua nho o production nem loi (L2)', function () {
    config(['auth.otp.cooldown_seconds' => 5]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('AUTH_OTP_MAX_ATTEMPTS_PER_CODE = 0 hoac qua lon o production nem loi (L2)', function (int $value) {
    config(['auth.otp.max_attempts_per_code' => $value]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
})->with([0, 255]);

test('AUTH_OTP_MAX_PER_DAY qua lon o production nem loi (L2)', function () {
    config(['auth.otp.max_per_day' => 1000]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('AUTH_OTP_MAX_VERIFY_PER_DAY = 0 o production nem loi (L2)', function () {
    config(['auth.otp.max_verify_per_day' => 0]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});
