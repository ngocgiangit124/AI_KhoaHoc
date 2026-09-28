<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

/**
 * R2/R3 (docs/qa/review-T03-FW1.md) — api-contract §1.6 ghi "10 lần SAI/giờ":
 * đăng nhập ĐÚNG lặp lại (vd nhiều tab/thiết bị hợp lệ trước khi T05 áp 1
 * phiên) KHÔNG được tính vào bộ đếm này. Trước khi sửa R2, test này FAIL ở
 * lần thứ 11 (middleware `throttle:login` cũ đếm mọi request, kể cả đúng).
 */
test('dang nhap dung nhieu lan lien tiep KHONG bi throttle (chi dem lan sai — R2)', function () {
    User::factory()->create([
        'email' => 'dung-nhieu-lan@example.com',
        'password' => Hash::make('matkhau123'),
    ]);
    $uri = 'http://'.config('app.api_host').'/api/v1/auth/login';

    for ($i = 0; $i < 12; $i++) {
        $response = test()->postJson($uri, [
            'login' => 'dung-nhieu-lan@example.com',
            'password' => 'matkhau123',
        ], ['Origin' => config('app.frontend_url')]);

        expect($response->status())->toBe(200);

        // Middleware `guest` chặn lệnh gọi kế tiếp nếu guard còn coi là đã
        // đăng nhập — guard là singleton xuyên suốt các lệnh gọi HTTP mô
        // phỏng nối tiếp trong 1 hàm test Pest (không phải hành vi HTTP thật,
        // xem ghi chú tương tự ở RegisterTest::vvLogoutGuard()).
        Auth::guard('web')->logout();
    }
});

/**
 * R7 (docs/qa/review-T03-FW1.md, review lần 2) — khoá throttle theo tài khoản
 * PHẢI dùng cùng 1 định danh đã chuẩn hoá với `findByLogin()`. Trước khi sửa,
 * gõ sai luân phiên 3 cách viết CÙNG 1 số điện thoại (`0912345678` /
 * `+84912345678` / `84912345678`) bị tính là 3 định danh khác nhau → không
 * bao giờ chạm ngưỡng 10 lần/giờ (mỗi dạng chỉ ăn ~3-4 lần).
 */
test('10 lan sai luan phien 3 dang viet cung 1 SDT van bi khoa throttle (R7)', function () {
    User::factory()->create(['phone' => '0912345678']);
    $uri = 'http://'.config('app.api_host').'/api/v1/auth/login';

    $variants = ['0912345678', '+84912345678', '84912345678'];

    for ($i = 0; $i < 10; $i++) {
        $response = test()->postJson($uri, [
            'login' => $variants[$i % count($variants)],
            'password' => 'sai-mat-khau',
        ], ['Origin' => config('app.frontend_url')]);

        expect($response->status())->not->toBe(429);
    }

    $blocked = test()->postJson($uri, [
        'login' => $variants[0],
        'password' => 'sai-mat-khau',
    ], ['Origin' => config('app.frontend_url')]);

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});

/**
 * R7 — cùng vấn đề với email khác hoa/thường (`findByLogin()` đã lowercase để
 * tra cứu, khoá throttle phải lowercase giống hệt, không được lệch nhau).
 */
test('10 lan sai luan phien email khac hoa/thuong van bi khoa throttle (R7)', function () {
    User::factory()->create(['email' => 'hoahong@example.com']);
    $uri = 'http://'.config('app.api_host').'/api/v1/auth/login';

    $variants = ['hoahong@example.com', 'HoaHong@Example.com', 'HOAHONG@EXAMPLE.COM'];

    for ($i = 0; $i < 10; $i++) {
        $response = test()->postJson($uri, [
            'login' => $variants[$i % count($variants)],
            'password' => 'sai-mat-khau',
        ], ['Origin' => config('app.frontend_url')]);

        expect($response->status())->not->toBe(429);
    }

    $blocked = test()->postJson($uri, [
        'login' => $variants[0],
        'password' => 'sai-mat-khau',
    ], ['Origin' => config('app.frontend_url')]);

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});
