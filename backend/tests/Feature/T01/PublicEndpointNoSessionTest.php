<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * M3 (review bảo mật T01/T02) — endpoint công khai, cache được (`Cache-Control:
 * public`) không được khởi tạo session/Set-Cookie dù request có Origin thuộc
 * SANCTUM_STATEFUL_DOMAINS (ADR-004 §2.5). Nếu không, một tầng cache phía
 * trước (CDN/Nginx micro-cache) có thể phát cookie phiên của người gọi đầu
 * tiên cho người khác.
 */
test('config public khong tao Set-Cookie du co Origin thuoc stateful domains', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/config/public', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([]);
});

test('health khong tao Set-Cookie du co Origin thuoc stateful domains', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/health', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});

test('csrf-token van tao session/cookie binh thuong (khong bi anh huong boi M3)', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    expect($response->headers->getCookies())->not->toBe([]);
});

test('csrf-token vuot nguong throttle:csrf tra 429 TOO_MANY_ATTEMPTS', function () {
    RateLimiter::for('csrf', fn () => Limit::perMinute(1));

    $first = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);
    $first->assertOk();

    $second = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);

    $second->assertStatus(429);
    $second->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});

test('admin csrf-token vuot nguong throttle:csrf tra 429 TOO_MANY_ATTEMPTS', function () {
    RateLimiter::for('csrf', fn () => Limit::perMinute(1));

    $first = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.admin_url'),
    ]);
    $first->assertOk();

    $second = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.admin_url'),
    ]);

    $second->assertStatus(429);
    $second->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});
