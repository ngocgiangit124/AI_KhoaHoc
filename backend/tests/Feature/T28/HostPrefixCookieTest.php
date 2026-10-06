<?php

/**
 * L3 (review bảo mật cụm 1): production đặt tên cookie phiên có tiền tố `__Host-` qua env. Trình duyệt chỉ nhận cookie này
 * khi Secure, Path=/ và KHÔNG có Domain, nên test khẳng định đúng 3 điều kiện bằng Set-Cookie thật (qua HTTPS) cho cả hai
 * host, và CSRF/Sanctum stateful vẫn chạy với tên mới.
 */
dataset('hostPrefixHosts', [
    'api (học sinh)' => ['api_host', 'frontend_url', 'session.cookie', '__Host-vv_session'],
    'admin-api (quản trị)' => ['admin_api_host', 'admin_url', 'session.admin_cookie', '__Host-vv_admin_session'],
]);

test('cookie phiên __Host-: Secure, Path=/, không Domain; csrf-token chạy', function (string $hostKey, string $originKey, string $cookieKey, string $name) {
    config([$cookieKey => $name, 'session.path' => '/', 'session.domain' => null]);

    $response = $this->getJson('https://'.config('app.'.$hostKey).'/api/v1/csrf-token', [
        'Origin' => config('app.'.$originKey),
    ]);

    $response->assertOk();
    expect($response->json())->toHaveKey('token');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue();
})->with('hostPrefixHosts');

test('mẫu env production dùng tiền tố __Host- cho cả hai cookie phiên', function () {
    $path = base_path('../infra/production/.env.production.example');

    if (! is_file($path)) {
        $this->markTestSkipped('infra/ không được mount trong container này.');
    }

    $env = (string) file_get_contents($path);

    expect($env)->toMatch('/^SESSION_COOKIE=__Host-vv_session\b/m')
        ->and($env)->toMatch('/^SESSION_ADMIN_COOKIE=__Host-vv_admin_session\b/m')
        ->and($env)->toMatch('/^SESSION_PATH=\/\s/m')
        ->and($env)->toMatch('/^SESSION_DOMAIN=null\b/m');
});
