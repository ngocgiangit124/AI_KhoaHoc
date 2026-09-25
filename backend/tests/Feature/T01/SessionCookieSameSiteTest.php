<?php

/**
 * R5 (review T01/T02) — khẳng định bằng response Set-Cookie thật (không chỉ
 * đọc config) rằng ConfigureHostContext + App\Http\Middleware\EncryptCookies
 * đặt đúng SameSite theo host (ADR-004 §2.2): admin-api = Strict, api = Lax.
 */
test('cookie phien vv_admin_session tren admin-api co SameSite=Strict', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.admin_url'),
    ]);

    $response->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.admin_cookie'));

    expect($cookie)->not->toBeNull();
    expect($cookie->getSameSite())->toBe('strict');
});

test('cookie phien vv_session tren host api co SameSite=Lax', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull();
    expect($cookie->getSameSite())->toBe('lax');
});
