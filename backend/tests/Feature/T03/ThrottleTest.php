<?php

use App\Models\User;

/**
 * S10/S18 (api-contract §1.6) — throttle 2 lớp: tài khoản + IP. `TRUSTED_PROXIES`
 * rỗng trong test (phpunit.xml) nên `X-Forwarded-For` giả từ IP không tin cậy
 * không đổi `$request->ip()` (đã kiểm ở T01 — TrustedProxyTest). Ở đây kiểm
 * thêm rằng bộ đếm throttle của T03 (`register`, `login`) không bị lách qua
 * việc đổi header này.
 */
test('X-Forwarded-For gia khong lach duoc throttle:register (S10)', function () {
    $uri = 'http://'.config('app.api_host').'/api/v1/auth/register';

    // register: 30/gio/IP (AppServiceProvider::configureRateLimiters()) — không
    // có limiter theo tài khoản nên payload không cần hợp lệ, chỉ cần request
    // đi qua được middleware throttle (chạy trước validation).
    for ($i = 0; $i < 30; $i++) {
        $response = test()->postJson($uri, [], [
            'Origin' => config('app.frontend_url'),
            'X-Forwarded-For' => "203.0.113.{$i}",
        ]);

        expect($response->status())->not->toBe(429);
    }

    $blocked = test()->postJson($uri, [], [
        'Origin' => config('app.frontend_url'),
        'X-Forwarded-For' => '203.0.113.250',
    ]);

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});

test('11 dia chi IP khac nhau cung 1 tai khoan van bi khoa throttle:login theo tai khoan (S18)', function () {
    $user = User::factory()->create(['email' => 'throttle-login@example.com']);
    $uri = 'http://'.config('app.api_host').'/api/v1/auth/login';

    // login: 10 lan sai/gio/tai khoan (không phụ thuộc IP) + 50/gio/IP — đổi
    // REMOTE_ADDR thật mỗi lần (không phải header giả) để cô lập đúng bộ đếm
    // theo TÀI KHOẢN, không phải bộ đếm theo IP (khác limiter, ngưỡng cao hơn).
    for ($i = 0; $i < 10; $i++) {
        $response = test()
            ->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
            ->postJson($uri, [
                'login' => 'throttle-login@example.com',
                'password' => 'sai-mat-khau',
            ], ['Origin' => config('app.frontend_url')]);

        expect($response->status())->not->toBe(429);
    }

    $blocked = test()
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
        ->postJson($uri, [
            'login' => 'throttle-login@example.com',
            'password' => 'sai-mat-khau',
        ], ['Origin' => config('app.frontend_url')]);

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});
