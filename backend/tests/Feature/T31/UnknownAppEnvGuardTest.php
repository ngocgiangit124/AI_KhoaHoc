<?php

use App\Support\ProductionConfigGuard;

/**
 * QA "Sửa lỗi nhỏ 3" — guard áp cho MỌI APP_ENV khác local/testing. Tên lạ/viết sai KHÔNG được coi là môi trường dev.
 */
beforeEach(function () {
    config([
        'app.debug' => true,
        'session.secure' => false,
        'captcha.driver' => 'fake',
        'app.trusted_proxies' => '*',
    ]);
});

function vvUnknownEnvCheck(): void
{
    (new ProductionConfigGuard)->check();
}

test('APP_ENV la/viet sai (Local, LOCAL, Testing, dev, qa, uat, local-prod, rong) + cau hinh sai -> bi chan', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => vvUnknownEnvCheck())->toThrow(RuntimeException::class);
})->with(['Local', 'LOCAL', 'Testing', 'dev', 'develop', 'qa', 'uat', 'local-prod', 'testing ', ' local', '']);

test('APP_ENV la (uat, prod) bi chan du moi cau hinh khac deu dung (C4-M2); production/staging khong bi chan oan', function () {
    foreach (['uat', 'prod'] as $unknown) {
        app()->detectEnvironment(fn () => $unknown);
        expect(fn () => vvUnknownEnvCheck())->toThrow(RuntimeException::class, 'APP_ENV');
    }

    app()->detectEnvironment(fn () => 'staging');
    config([
        'app.debug' => false,
        'session.secure' => true, 'session.encrypt' => true,
        'captcha.driver' => 'turnstile',
        'auth.otp.channels' => ['email'],
        'auth.otp.e2e_relaxed' => false,
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'],
        'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [],
        'video.provider' => 'internal',
        'video.enabled_providers' => ['internal'],
        'internal.required' => true,
        'internal.ssr_token' => str_repeat('a', 64),
        'internal.ssr_token_min_length' => 32,
        'features.paid_checkout' => false,
        'videolab.enabled' => true,
        'videolab.api_key' => str_repeat('a', 32),
        'videolab.token_key' => str_repeat('b', 32),
        'videolab.webhook_secret' => str_repeat('c', 32),
    ]);

    expect(fn () => vvUnknownEnvCheck())->not->toThrow(RuntimeException::class);
});
