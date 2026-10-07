<?php

use App\Support\ProductionConfigGuard;

require_once __DIR__.'/helpers.php';

function bzGuardBase(string $env = 'production', array $bunny = []): void
{
    app()->detectEnvironment(fn () => $env);
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn'], 'app.trusted_proxies' => '10.0.0.1', 'app.static_url' => 'https://static.vitaminvui-media.net',
        'payments.enabled_gateways' => ['momo'], 'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/x',
    ]);
    bzConfigure($bunny, ['bunny', 'internal']);
}

function bzGuardMessage(): ?string
{
    try {
        (new ProductionConfigGuard)->check();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return null;
}

test('guard: bunny day du -> khong loi (production va staging)', function (string $env) {
    bzGuardBase($env);

    expect(bzGuardMessage())->toBeNull();
    bzConfigure(['cdn_host' => 'https://vz-test.b-cdn.net'], ['bunny']);
    expect(bzGuardMessage())->toBeNull();
})->with(['production', 'staging']);

test('guard AC14: thieu bien nao -> loi neu dung ten bien, khong in gia tri', function (string $key, string $env) {
    bzGuardBase('production', [$key => null]);

    $msg = bzGuardMessage();

    expect($msg)->toContain($env)->and($msg)->not->toContain(BZ_API_KEY)->and($msg)->not->toContain(BZ_TOKEN_KEY);
})->with([
    ['library_id', 'BUNNY_LIBRARY_ID'], ['api_key', 'BUNNY_API_KEY'], ['cdn_host', 'BUNNY_CDN_HOST'], ['token_key', 'BUNNY_TOKEN_KEY'],
]);

test('guard: bunny la provider mac dinh ma thieu khoa cung bi chan; bunny khong bat thi bo qua', function () {
    bzGuardBase('production', ['token_key' => '']);
    config(['video.provider' => 'bunny', 'video.enabled_providers' => ['internal']]);
    expect(bzGuardMessage())->toContain('BUNNY_TOKEN_KEY');

    config(['video.provider' => 'internal', 'video.enabled_providers' => ['internal']]);
    expect(bzGuardMessage())->toBeNull();
});

test('guard BR11: CDN host http, IP, co duong dan, trung host app, nam duoi SESSION_DOMAIN, api_base/tus http -> loi', function (array $bunny, array $cfg) {
    bzGuardBase('production', $bunny);
    config($cfg);

    expect(bzGuardMessage())->not->toBeNull();
})->with([
    'http' => [['cdn_host' => 'http://vz.b-cdn.net'], []],
    'IP' => [['cdn_host' => '203.0.113.9'], []],
    'duong dan' => [['cdn_host' => 'vz.b-cdn.net/x'], []],
    'trung API host' => [['cdn_host' => 'api.vitaminvui.vn'], ['app.api_host' => 'api.vitaminvui.vn']],
    'trung FRONTEND_URL' => [['cdn_host' => 'vitaminvui.vn'], ['app.frontend_url' => 'https://vitaminvui.vn']],
    'duoi SESSION_DOMAIN' => [['cdn_host' => 'video.vitaminvui.vn'], ['session.domain' => '.vitaminvui.vn']],
    'api_base http' => [['api_base' => 'http://video.bunnycdn.com'], []],
    'tus http' => [['tus_endpoint' => 'http://video.bunnycdn.com/tusupload'], []],
]);

test('guard S1: thieu/ngan BUNNY_WEBHOOK_TOKEN khi bunny bat -> loi neu dung ten bien', function (mixed $token) {
    bzGuardBase('production', ['webhook_token' => $token]);

    $msg = bzGuardMessage();

    expect($msg)->toContain('BUNNY_WEBHOOK_TOKEN');
    bzAssertNoSecret((string) $msg);
})->with([[null], [''], ['short']]);

test('guard: local/testing bo qua (khong can khoa Bunny)', function () {
    bzGuardBase('local', ['api_key' => null]);

    expect(bzGuardMessage())->toBeNull();
});
