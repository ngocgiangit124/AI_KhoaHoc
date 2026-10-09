<?php

use App\Enums\UserStatus;
use App\Services\Auth\LoginService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/LoginTest.php';

// "Sửa lỗi nhỏ 2": T03 M2 (né giới hạn bằng email có dấu/hoa thường) và M3 (bộ đếm không nguyên tử).

test('M2 accountKey bo dau va ha chu: cac cach viet cua email khong con khac khoa', function () {
    expect(LoginService::accountKey('HS@Example.com'))->toBe(LoginService::accountKey('hs@example.com'))
        ->and(LoginService::accountKey('hs@exámple.com'))->toBe('hs@example.com')
        ->and(LoginService::accountKey('Đỗ.Văn@Example.com'))->toBe('do.van@example.com');
});

test('M2 11 cach viet co dau/hoa thuong cua 1 email -> luot thu 11 van 429 (tai khoan co that)', function () {
    vvStudent();
    $variants = [
        'hs@example.com', 'HS@example.com', 'hs@Example.com', 'hs@exámple.com', 'hs@examplé.com',
        'hś@example.com', 'hs@éxample.com', 'HS@EXAMPLE.COM', 'hs@exàmple.com', 'hs@exãmple.com',
    ];

    foreach ($variants as $login) {
        vvLogin(['login' => $login, 'password' => 'sai'])->assertStatus(422);
        vvResetClient();
    }

    // Cách viết thứ 11 (kể cả mật khẩu ĐÚNG) bị chặn: cùng 1 bộ đếm theo user id.
    vvLogin(['login' => 'hs@exâmple.com', 'password' => 'dung-mat-khau-1'])->assertStatus(429);
    $this->assertGuest('web');
});

test('M2 tai khoan khong ton tai: cac cach viet co dau cung chung 1 bo dem', function () {
    $variants = ['ai@example.com', 'AI@example.com', 'aí@example.com', 'ai@éxample.com', 'aì@example.com',
        'ai@exámple.com', 'AÍ@example.com', 'ai@exãmple.com', 'ai@exàmple.com', 'ai@exâmple.com'];

    foreach ($variants as $login) {
        vvLogin(['login' => $login, 'password' => 'sai'])->assertStatus(422);
    }

    vvLogin(['login' => 'ai@exămple.com', 'password' => 'sai'])->assertStatus(429);
});

test('M3 dem TRUOC khi so mat khau: luc Hash::check chay, luot hien tai da nam trong bo dem', function () {
    $user = vvStudent();
    $seen = [];

    Hash::shouldReceive('check')->andReturnUsing(function () use ($user, &$seen) {
        $seen[] = RateLimiter::attempts('login-fail:u:'.$user->getKey());

        return false;
    });

    vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
    vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);

    expect($seen)->toBe([1, 2]);
});

test('M3 30 luot sai lien tiep: dung 10 lan so mat khau, con lai bi chan truoc khi so', function () {
    $user = vvStudent();
    $checks = 0;

    Hash::shouldReceive('check')->andReturnUsing(function () use (&$checks) {
        $checks++;

        return false;
    });

    $statuses = [];
    for ($i = 0; $i < 30; $i++) {
        $statuses[] = vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->status();
        vvResetClient();
    }

    expect($checks)->toBe(10)
        ->and(array_count_values($statuses))->toBe([422 => 10, 429 => 20]);
    expect($user->fresh())->not->toBeNull();
});

test('M3 mat khau dung hoan luot da giu cho: khong tieu hao han muc IP/tai khoan', function () {
    $user = vvStudent();

    vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

    expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(0);
    expect(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(1);
});

test('R1 IP da vuot 50 luot: 15 request vao tai khoan X deu 429 nhung bo dem X khong tang; IP khac van dang nhap duoc', function () {
    $user = vvStudent();
    $ipKey = 'login-fail-ip:127.0.0.1';
    for ($i = 0; $i < 50; $i++) {
        RateLimiter::hit($ipKey, 3600);
    }

    for ($i = 0; $i < 15; $i++) {
        // GL-A2 V2-3b: không captcha mà chạm trần IP -> 422 CAPTCHA_REQUIRED (không để lại lượt ở tài khoản).
        vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    }

    expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(0)
        ->and(RateLimiter::attempts($ipKey))->toBe(50); // không phình thêm (R2)

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7']);
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
});

test('R1 tai khoan da vuot 10 luot: request bi chan khong tang bo dem IP', function () {
    $user = vvStudent();
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('login-fail:u:'.$user->getKey(), 3600);
    }

    for ($i = 0; $i < 5; $i++) {
        vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(429);
    }

    expect(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(0)
        ->and(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(10);
});

test('M3 mat khau dung nhung tai khoan bi khoa (ACCOUNT_LOCKED) hoan luot', function () {
    $user = vvStudent(['status' => UserStatus::Locked]);

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_LOCKED');

    expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(0)
        ->and(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(0);
});

test('M3 mat khau dung nhung sai cong (WRONG_PORTAL) hoan luot', function () {
    $teacher = vvStudent(['role' => 'giao_vien']);

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(403)->assertJsonPath('code', 'WRONG_PORTAL');

    expect(RateLimiter::attempts('login-fail:u:'.$teacher->getKey()))->toBe(0)
        ->and(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(0);
});
