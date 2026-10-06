<?php

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';

// QA "Sửa lỗi nhỏ 2" cho staff: MFA sau khi đăng nhập đúng, tài khoản bị khoá không lộ thông tin.

test('staff: dang nhap dung (sau vai luot sai) van sang buoc MFA va verify OK; bo dem duoc hoan', function () {
    config(['features.staff_mfa' => true]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');

    foreach (range(1, 3) as $i) {
        vvAdminLogin((string) $admin->email, 'sai')->assertStatus(422);
    }
    $login = vvAdminLogin(strtoupper((string) $admin->email))->assertOk()->assertJsonPath('mfa_required', true);
    vvAdminFollow($login);

    expect(RateLimiter::attempts('staff-login-fail:u:'.$admin->getKey()))->toBe(0);

    app('auth')->forgetGuards();
    $verify = test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())->assertOk();
    vvAdminFollow($verify);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('staff: bi khoa - sai mat khau tra y het tai khoan khong ton tai; mat khau dung moi lo ACCOUNT_LOCKED va hoan luot', function () {
    $locked = vvStaffUser('teacher', ['status' => 'locked']);

    $a = vvAdminLogin((string) $locked->email, 'sai')->assertStatus(422);
    $b = vvAdminLogin('khongco@example.com', 'sai')->assertStatus(422);
    expect(Arr::except($a->json(), 'request_id'))->toBe(Arr::except($b->json(), 'request_id'));

    vvAdminLogin((string) $locked->email)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_LOCKED');
    expect(RateLimiter::attempts('staff-login-fail:u:'.$locked->getKey()))->toBe(1); // chỉ lượt sai ban đầu
});

test('staff: ghost va tai khoan that dung chung hanh vi 429 o luot 11', function () {
    $t = vvStaffUser('teacher');
    $real = [];
    $ghost = [];
    foreach (range(1, 11) as $i) {
        $real[] = vvAdminLogin((string) $t->email, 'sai')->status();
    }
    foreach (range(1, 11) as $i) {
        $ghost[] = vvAdminLogin('ghost@example.com', 'sai')->status();
    }

    expect($ghost)->toBe($real)->and($real[10])->toBe(429);
    expect(User::query()->count())->toBeGreaterThan(0);
});
