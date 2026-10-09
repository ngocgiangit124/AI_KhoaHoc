<?php

use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';

// "Sửa lỗi nhỏ 2" (M2/M3/R1) cho đăng nhập quản trị.

test('staff: 10 luot sai -> luot 11 (ca mat khau dung) 429, khoa theo user id bat ke cach viet email', function () {
    $teacher = vvStaffUser('teacher');
    $email = (string) $teacher->email;

    foreach (range(1, 10) as $i) {
        vvAdminLogin($i % 2 ? strtoupper($email) : $email, 'sai')->assertStatus(422);
    }

    vvAdminLogin($email)->assertStatus(429);
    expect(RateLimiter::attempts('staff-login-fail:u:'.$teacher->getKey()))->toBe(10);
});

test('staff: mat khau dung hoan luot da giu cho (tai khoan va IP)', function () {
    $teacher = vvStaffUser('teacher');

    vvAdminLogin((string) $teacher->email, 'sai')->assertStatus(422);
    vvAdminLogin((string) $teacher->email)->assertOk();

    expect(RateLimiter::attempts('staff-login-fail:u:'.$teacher->getKey()))->toBe(0)
        ->and(RateLimiter::attempts('staff-login-fail-ip:127.0.0.1'))->toBe(1);
});

test('staff: IP vuot nguong -> 429 khong tang bo dem tai khoan', function () {
    $teacher = vvStaffUser('teacher');
    $max = (int) config('auth.staff.login_max_failures_per_ip');
    for ($i = 0; $i < $max; $i++) {
        RateLimiter::hit('staff-login-fail-ip:127.0.0.1', 3600);
    }

    foreach (range(1, 5) as $i) {
        vvAdminLogin((string) $teacher->email, 'sai')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED'); // GL-A2 V2-3b
    }

    expect(RateLimiter::attempts('staff-login-fail:u:'.$teacher->getKey()))->toBe(0)
        ->and(RateLimiter::attempts('staff-login-fail-ip:127.0.0.1'))->toBe($max);
});
