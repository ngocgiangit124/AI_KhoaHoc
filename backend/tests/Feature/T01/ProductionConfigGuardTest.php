<?php

use App\Support\ProductionConfigGuard;

/**
 * M4 (review bảo mật T01/T02) — mỗi cấu hình sai phải làm app "không boot"
 * (ném RuntimeException) khi ở production. Gọi trực tiếp `check()` sau khi ép
 * environment/config vì không thể khởi động lại cả ứng dụng trong 1 tiến trình
 * test — đúng tinh thần bài test, không phải khởi động lại `php-fpm` thật.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    // Baseline hợp lệ — mỗi test chỉ phá đúng 1 điều kiện.
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
        // T04 security review L2 — `guardOtpMailer()` mới thêm cấm
        // `log`/`array` ở production; phpunit.xml ép MAIL_MAILER=array cho
        // testing nên baseline "hợp lệ" ở đây phải tự ghi đè sang driver thật.
        'mail.default' => 'smtp',
    ]);
});

test('cau hinh hop le thi khong nem loi', function () {
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('khong o production thi bo qua moi kiem tra', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['app.debug' => true, 'session.secure' => false]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('APP_DEBUG=true o production nem loi', function () {
    config(['app.debug' => true]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('session.secure tat o production nem loi', function () {
    config(['session.secure' => false]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('CAPTCHA_DRIVER=fake o production nem loi (khong phan biet hoa thuong)', function () {
    config(['captcha.driver' => 'FAKE']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

/**
 * M3 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY guard chỉ cấm ĐÚNG
 * chuỗi `fake` (blocklist): mọi biến thể khác đều lọt (và binding của
 * `AppServiceProvider` coi các giá trị đó là "không phải turnstile" → bind
 * `FakeCaptchaVerifier`, production chạy không có captcha thật). Giờ CHỈ
 * đúng `turnstile` mới qua được guard.
 */
test('CAPTCHA_DRIVER bien the sai chinh ta/viet hoa/rong o production deu nem loi (M3)', function (string $driver) {
    config(['captcha.driver' => $driver]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
})->with(['Turnstile', 'TURNSTILE', 'turnstile ', '', 'none']);

test('CAPTCHA_DRIVER=turnstile nhung thieu TURNSTILE_SECRET o production nem loi (M3)', function () {
    config(['services.turnstile.secret' => null]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('CAPTCHA_DRIVER=turnstile nhung thieu TURNSTILE_SITE_KEY o production nem loi (M3)', function () {
    config(['services.turnstile.site_key' => null]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('SANCTUM_STATEFUL_DOMAINS chua localhost o production nem loi', function () {
    config(['sanctum.stateful' => ['vitaminvui.vn', 'localhost:3000']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('SANCTUM_STATEFUL_DOMAINS chua 127.0.0.1 o production nem loi', function () {
    config(['sanctum.stateful' => ['127.0.0.1:8000']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('TRUSTED_PROXIES la wildcard o production nem loi', function () {
    config(['app.trusted_proxies' => '*']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('gateway fake viet hoa o production van bi chan (khong phan biet hoa thuong)', function () {
    config(['payments.enabled_gateways' => ['Fake']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('MOMO_ENDPOINT ngoai allowlist o production nem loi', function () {
    config(['payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn/v2/gateway/api/create']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('MOMO_ENDPOINT sai scheme (http) o production nem loi', function () {
    config(['payments.gateways.momo.endpoint' => 'http://payment.momo.vn/v2/gateway/api/create']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});
