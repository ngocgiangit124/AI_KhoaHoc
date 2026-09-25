<?php

use Illuminate\Support\Facades\Route;

/**
 * R1 (review T01/T02) — route mặc định `GET /sanctum/csrf-cookie` của Sanctum
 * không khai `Route::domain()` và không có `admin.origin`: trước khi tắt, nó
 * trả 200 và set cookie phiên trên CẢ 2 host, kể cả admin-api từ Origin bất
 * kỳ (S6). Đã tắt bằng `config('sanctum.routes') = false`
 * (config/sanctum.php) — khẳng định bằng route:list (không còn tên route
 * `sanctum.csrf-cookie`) và bằng HTTP thật (404) trên cả 2 host.
 */
test('route sanctum.csrf-cookie khong con duoc dang ky', function () {
    expect(Route::has('sanctum.csrf-cookie'))->toBeFalse();
});

test('GET sanctum/csrf-cookie tra 404 tren host api', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/sanctum/csrf-cookie');

    $response->assertNotFound();
});

test('GET sanctum/csrf-cookie tra 404 tren host admin-api du co Origin dung', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/sanctum/csrf-cookie', [
        'Origin' => config('app.admin_url'),
    ]);

    $response->assertNotFound();
    $response->assertCookieMissing(config('session.admin_cookie'));
});
