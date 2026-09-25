<?php

test('csrf-token tren host api tra loi co kiem soat khi khong co Origin/Referer (R3)', function () {
    // Không gửi Origin/Referer → Sanctum không gắn StartSession → không có session.
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token');

    $response->assertStatus(400);
    $response->assertJsonStructure(['message', 'code', 'request_id']);
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('csrf-token tren host api tra 200 khi co Origin dung', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/csrf-token', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token']);
});
