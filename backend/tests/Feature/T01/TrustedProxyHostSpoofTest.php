<?php

use App\Http\Middleware\ConfigureHostContext;
use App\Http\Middleware\TrustHosts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * H1 (review bảo mật T01/T02) — khi có proxy tin cậy (TRUSTED_PROXIES ở
 * staging/production), request giả `X-Forwarded-Host` KHÔNG được đổi
 * route/cookie/CORS đang chọn theo Host thật. Mô phỏng bằng cách khai
 * `TrustProxies::at()` trực tiếp cho IP của test client (127.0.0.1 mặc định).
 */
test('TrustProxies chay truoc TrustHosts va ConfigureHostContext trong global middleware', function () {
    $middleware = app(Kernel::class)->getGlobalMiddleware();

    $posTrustProxies = array_search(TrustProxies::class, $middleware, true);
    $posTrustHosts = array_search(TrustHosts::class, $middleware, true);
    $posConfigureHost = array_search(ConfigureHostContext::class, $middleware, true);

    expect($posTrustProxies)->not->toBeFalse()
        ->and($posTrustHosts)->not->toBeFalse()
        ->and($posConfigureHost)->not->toBeFalse()
        ->and($posTrustProxies)->toBeLessThan($posTrustHosts)
        ->and($posTrustProxies)->toBeLessThan($posConfigureHost);
});

test('X-Forwarded-Host gia voi trusted proxy khong doi CORS/route sang admin-api (H1)', function () {
    TrustProxies::at(['127.0.0.1']);

    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/config/public', [
        'Origin' => config('app.frontend_url'),
        'X-Forwarded-Host' => config('app.admin_api_host'),
    ]);

    // Vẫn là route/CORS của host api — KHÔNG bị "chuyển" sang admin-api.
    $response->assertOk();
    $response->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'));
});

test('X-Forwarded-Host gia voi trusted proxy khong doi cookie phien sang vv_admin_session (H1)', function () {
    TrustProxies::at(['127.0.0.1']);

    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
        'X-Forwarded-Host' => config('app.admin_api_host'),
    ]);

    $response->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull();
    expect($cookie->getSameSite())->toBe('lax');

    $adminCookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.admin_cookie'));
    expect($adminCookie)->toBeNull();
});

test('khong co trusted proxy thi X-Forwarded-Host bi bo qua hoan toan (doi chung, khong chi khi co proxy)', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/config/public', [
        'X-Forwarded-Host' => config('app.admin_api_host'),
    ]);

    $response->assertOk();
});
