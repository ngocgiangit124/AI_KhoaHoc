<?php

use App\Support\ProductionConfigGuard;

/**
 * T04 (S9, S11) — `ProductionConfigGuard::guardOtpChannels()`. Cùng khuôn với
 * `tests/Feature/T01/ProductionConfigGuardTest.php`: gọi `check()` trực tiếp
 * sau khi ép environment/config.
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
