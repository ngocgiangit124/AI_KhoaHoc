<?php

use App\Support\ProductionConfigGuard;

/**
 * T31 — ProductionConfigGuard phủ cả staging; khoá VideoLab, OTP e2e, pay_url, cờ thanh toán.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'session.secure' => true, 'session.encrypt' => true,
        'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'],
        'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
        'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn'],
        'features.paid_checkout' => false,
        'auth.otp.e2e_relaxed' => false,
        'videolab.enabled' => true,
        'videolab.api_key' => str_repeat('a', 32),
        'videolab.token_key' => str_repeat('b', 32),
        'videolab.webhook_secret' => str_repeat('c', 32),
    ]);
});

function vvGuard(): void
{
    (new ProductionConfigGuard)->check();
}

test('cau hinh hop le o production va staging khong nem loi', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);
})->with(['production', 'staging']);

test('local va testing van duoc noi', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['captcha.driver' => 'fake', 'videolab.api_key' => 'x', 'auth.otp.e2e_relaxed' => true, 'app.debug' => true]);

    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);
})->with(['local', 'testing']);

test('CAPTCHA_DRIVER=fake bi chan o staging (khong phan biet hoa thuong)', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['captcha.driver' => 'Fake']);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'CAPTCHA_DRIVER');
});

test('APP_DEBUG, session.secure, gateway fake, TRUSTED_PROXIES=* deu bi chan o staging', function (array $override) {
    app()->detectEnvironment(fn () => 'staging');
    config($override);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class);
})->with([
    'debug' => [['app.debug' => true]],
    'cookie' => [['session.secure' => false]],
    'gateway fake' => [['payments.enabled_gateways' => ['fake']]],
    'proxy *' => [['app.trusted_proxies' => '*']],
    'sms' => [['auth.otp.channels' => ['sms']]],
    'stateful localhost' => [['sanctum.stateful' => ['localhost:3000']]],
]);

test('staging duoc dung endpoint sandbox MoMo, production thi khong', function () {
    config([
        'payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn/v2/gateway/api/create',
        'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn', 'test-payment.momo.vn'],
    ]);

    app()->detectEnvironment(fn () => 'staging');
    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'MOMO_ENDPOINT');
});

test('MOMO_PAY_URL_HOSTS co host sandbox bi chan o production', function () {
    config(['payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn', 'test-payment.momo.vn']]);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'MOMO_PAY_URL_HOSTS');
});

test('khoa VideoLab thieu hoac ngan hon 32 ky tu bi chan o staging va production', function (string $env, string $key, mixed $value) {
    app()->detectEnvironment(fn () => $env);
    config(["videolab.{$key}" => $value]);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'VIDEOLAB_'.strtoupper($key));
})->with([
    ['staging', 'api_key', str_repeat('a', 31)],
    ['production', 'token_key', 'ngan'],
    ['production', 'webhook_secret', null],
    ['staging', 'webhook_secret', ''],
]);

test('khoa VideoLab dung 32 ky tu thi qua; VideoLab tat thi bo qua khoa', function () {
    config(['videolab.api_key' => str_repeat('z', 32)]);
    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);

    config(['videolab.enabled' => false, 'videolab.api_key' => null]);
    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);
});

test('AUTH_OTP_E2E_RELAXED bat o staging/production bi chan', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['auth.otp.e2e_relaxed' => true]);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'AUTH_OTP_E2E_RELAXED');
})->with(['production', 'staging']);

test('FEATURE_PAID_CHECKOUT bat ma khong co cong thanh toan bi chan', function () {
    config(['features.paid_checkout' => true, 'payments.enabled_gateways' => []]);
    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'FEATURE_PAID_CHECKOUT');

    config(['payments.enabled_gateways' => ['momo'], 'payments.ipn_ready' => true]);
    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);
});

test('APP_ENV viet khac chuan (Production, prod, stage, uat) bi chan ngay (C4-M2), khong con lot qua guard', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'APP_ENV');
})->with(['Production', 'prod', 'stage', 'uat']);

test('allowlist MoMo chi ep o dung production; staging duoc dung sandbox', function () {
    config(['payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn/x']);

    app()->detectEnvironment(fn () => 'staging');
    expect(fn () => vvGuard())->not->toThrow(RuntimeException::class);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => vvGuard())->toThrow(RuntimeException::class, 'MOMO_ENDPOINT');
});
