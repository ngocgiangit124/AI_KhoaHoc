<?php

test('csrf-token tren admin-api bi tu choi khi thieu Origin dung', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token');

    $response->assertForbidden();
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('csrf-token tren admin-api bi tu choi khi Origin la mot domain khac (khong phai chi thieu Origin)', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => 'http://evil.test',
    ]);

    $response->assertForbidden();
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
    $response->assertJsonStructure(['message', 'code', 'request_id']);
});

test('csrf-token tren admin-api bi tu choi khi Origin la ADMIN_URL that nhung sai scheme/port', function () {
    // http vs https, hoac sai cong — phai khop tuyet doi voi ADMIN_URL cau hinh.
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => 'https://admin.localhost:3001',
    ]);

    $response->assertForbidden();
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('csrf-token tren admin-api bi tu choi khi Referer la origin khac tren request GET', function () {
    // EnsureAdminOrigin dung Referer cho GET khi thieu Origin (S6) — Referer
    // tro toi origin khac ADMIN_URL cung phai bi chan.
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Referer' => 'http://evil.test/some/page',
    ]);

    $response->assertForbidden();
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('csrf-token tren admin-api tra 200 voi Origin dung ADMIN_URL', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.admin_url'),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);
});

test('csrf-token tren host api khong yeu cau admin.origin', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);
});
