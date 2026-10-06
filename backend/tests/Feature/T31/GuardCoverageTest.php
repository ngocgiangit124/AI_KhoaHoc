<?php

use App\Support\ProductionConfigGuard;

/**
 * T31 QA — đối chiếu từng kiểm tra của guard với checklist: mỗi biến có ca chặn lẫn ca cho qua.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'session.secure' => true,
        'captcha.driver' => 'turnstile',
        'auth.otp.channels' => ['email'],
        'auth.otp.e2e_relaxed' => false,
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'],
        'app.trusted_proxies' => '10.0.0.1,10.0.0.2',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
        'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn'],
        'video.provider' => 'internal',
        'video.enabled_providers' => ['internal'],
        'internal.required' => true,
        'internal.ssr_token' => str_repeat('t', 64),
        'internal.ssr_token_min_length' => 32,
        'features.paid_checkout' => false,
        'videolab.enabled' => true,
        'videolab.api_key' => str_repeat('a', 32),
        'videolab.token_key' => str_repeat('b', 32),
        'videolab.webhook_secret' => str_repeat('c', 32),
    ]);
});

function qaCheck(): void
{
    (new ProductionConfigGuard)->check();
}

test('AC-nen: bo env hop le qua guard o moi moi truong khong phai local/testing', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with(['production', 'staging', 'Production', 'prod', 'stage']);

test('APP_DEBUG: true bi chan, false qua', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['app.debug' => true]);
    expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'APP_DEBUG');
    config(['app.debug' => false]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with(['production', 'staging']);

test('SESSION_SECURE_COOKIE: false/null bi chan, true qua', function (mixed $value) {
    config(['session.secure' => $value]);
    expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'SESSION_SECURE_COOKIE');
})->with([false, null]);

test('CAPTCHA_DRIVER: fake bi chan, turnstile qua', function (string $driver, bool $blocked) {
    config(['captcha.driver' => $driver]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'CAPTCHA_DRIVER')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([['fake', true], ['FAKE', true], ['turnstile', false]]);

test('AUTH_OTP_CHANNELS: sms bi chan (ke ca viet hoa va kenh kep), email qua', function (array $channels, bool $blocked) {
    config(['auth.otp.channels' => $channels]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'AUTH_OTP_CHANNELS')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([[['sms'], true], [['SMS'], true], [['email', 'sms'], true], [['email'], false], [[], false]]);

test('SANCTUM_STATEFUL_DOMAINS: localhost/127.0.0.1 bi chan, ten mien that qua', function (array $domains, bool $blocked) {
    config(['sanctum.stateful' => $domains]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'SANCTUM_STATEFUL_DOMAINS')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([
    [['localhost'], true],
    [['LOCALHOST:3000'], true],
    [['vitaminvui.vn', '127.0.0.1'], true],
    [['127.0.0.1:8000'], true],
    [['vitaminvui.vn'], false],
]);

test('TRUSTED_PROXIES: * bi chan (ke ca nam giua danh sach, co khoang trang), IP cu the hoac rong qua', function (string $value, bool $blocked) {
    config(['app.trusted_proxies' => $value]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'TRUSTED_PROXIES')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([['*', true], ['10.0.0.1, *', true], [' * ', true], ['10.0.0.1', false], ['', false]]);

test('PAYMENT_GATEWAYS: fake bi chan (ke ca viet hoa va kenh kep), momo hoac rong qua', function (array $gateways, bool $blocked) {
    config(['payments.enabled_gateways' => $gateways]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'FakeGateway')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([[['fake'], true], [['Fake'], true], [['momo', 'fake'], true], [['momo'], false], [[], false]]);

test('MOMO_ENDPOINT o production: chi https://payment.momo.vn, moi bien the khac bi chan', function (string $endpoint, bool $blocked) {
    config(['payments.gateways.momo.endpoint' => $endpoint]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'MOMO_ENDPOINT')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([
    ['https://test-payment.momo.vn/v2', true],
    ['http://payment.momo.vn/v2', true],
    ['https://payment.momo.vn.evil.com/v2', true],
    ['https://evil.com/payment.momo.vn', true],
    ['', true],
    ['https://payment.momo.vn/v2/gateway/api/create', false],
]);

test('MOMO_ENDPOINT khong bi kiem khi momo khong bat', function () {
    config(['payments.enabled_gateways' => [], 'payments.gateways.momo.endpoint' => 'http://evil']);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
});

test('MOMO_PAY_URL_HOSTS o production: chi payment.momo.vn (khong phan biet hoa thuong)', function (array $hosts, bool $blocked) {
    config(['payments.gateways.momo.pay_url_hosts' => $hosts]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'MOMO_PAY_URL_HOSTS')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([
    [['payment.momo.vn', 'evil.com'], true],
    [['evil.com'], true],
    [[], true],
    [['Payment.Momo.VN'], false],
    [['payment.momo.vn'], false],
]);

test('VIDEO_PROVIDER / VIDEO_ENABLED_PROVIDERS: fake bi chan, internal qua', function (string $provider, array $enabled, bool $blocked) {
    config(['video.provider' => $provider, 'video.enabled_providers' => $enabled]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'FakeVideoProvider')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([
    ['fake', ['internal'], true],
    ['FAKE', ['internal'], true],
    ['internal', ['internal', 'fake'], true],
    ['internal', ['internal'], false],
]);

test('INTERNAL_API_TOKEN: bat buoc khi REQUIRED, >= 32 ky tu, de trong khi khong bat buoc thi qua', function (bool $required, mixed $token, bool $blocked) {
    config(['internal.required' => $required, 'internal.ssr_token' => $token]);
    $blocked
        ? expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'INTERNAL_API_TOKEN')
        : expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with([
    'required, null' => [true, null, true],
    'required, rong' => [true, '', true],
    'required, 31 ky tu' => [true, str_repeat('x', 31), true],
    'khong required, 31 ky tu' => [false, str_repeat('x', 31), true],
    'required, 32 ky tu' => [true, str_repeat('x', 32), false],
    'khong required, null' => [false, null, false],
    'khong required, rong' => [false, '', false],
]);

test('VIDEOLAB_*: moi khoa 31 ky tu bi chan, 32 qua; VideoLab tat thi bo qua', function (string $key) {
    config(["videolab.{$key}" => str_repeat('k', 31)]);
    expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'VIDEOLAB_'.strtoupper($key));

    config(["videolab.{$key}" => str_repeat('k', 32)]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);

    config(['videolab.enabled' => false, "videolab.{$key}" => null]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with(['api_key', 'token_key', 'webhook_secret']);

test('FEATURE_PAID_CHECKOUT: bat + khong cong bi chan; bat + co cong qua; tat + khong cong qua', function () {
    config(['features.paid_checkout' => true, 'payments.enabled_gateways' => []]);
    expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'FEATURE_PAID_CHECKOUT');

    config(['payments.enabled_gateways' => ['momo']]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);

    config(['features.paid_checkout' => false, 'payments.enabled_gateways' => []]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
});

test('AUTH_OTP_E2E_RELAXED: true bi chan, false qua', function () {
    config(['auth.otp.e2e_relaxed' => true]);
    expect(fn () => qaCheck())->toThrow(RuntimeException::class, 'AUTH_OTP_E2E_RELAXED');
    config(['auth.otp.e2e_relaxed' => false]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
});

test('local va testing khong bi chan du moi cau hinh deu sai', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config([
        'app.debug' => true, 'session.secure' => false, 'captcha.driver' => 'fake',
        'auth.otp.channels' => ['sms'], 'auth.otp.e2e_relaxed' => true,
        'sanctum.stateful' => ['localhost'], 'app.trusted_proxies' => '*',
        'payments.enabled_gateways' => ['fake'], 'video.provider' => 'fake',
        'internal.ssr_token' => null, 'features.paid_checkout' => true,
        'videolab.api_key' => null,
    ]);
    expect(fn () => qaCheck())->not->toThrow(RuntimeException::class);
})->with(['local', 'testing']);
