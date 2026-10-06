<?php

use App\Models\User;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

/**
 * QA "Bảo mật cụm 1" — cookie đổi chéo host và phiên đang chờ MFA.
 */
test('phien dang cho MFA goi /admin/staff -> 403 MFA_REQUIRED, khong doc duoc danh sach; qua MFA moi vao duoc', function () {
    config(['features.staff_mfa' => true]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');

    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk()->assertJsonPath('mfa_required', true));

    app('auth')->forgetGuards();
    vvAdminGet('/admin/staff')->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED')->assertJsonMissingPath('data');
    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/staff'), ['name' => 'X'], vvAdminHeaders())->assertForbidden()->assertJsonPath('code', 'MFA_REQUIRED');

    app('auth')->forgetGuards();
    $verify = test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())->assertOk();
    vvAdminFollow($verify);
    app('auth')->forgetGuards();
    vvAdminGet('/admin/staff')->assertOk();
});

test('cookie phien hoc sinh (vv_session) dat vao ten cookie admin o admin-api -> 401, khong vao duoc route quan tri', function () {
    $student = User::factory()->student()->create();
    vvResetClient();
    $login = test()->postJson(vvApiUrl('/auth/login'), [
        'login' => $student->email, 'password' => 'password', 'captcha_token' => 'ok',
    ], vvWebHeaders())->assertOk();
    $studentCookie = $login->getCookie((string) config('session.cookie'))->getValue();

    app('auth')->forgetGuards();
    test()->flushSession();
    test()->withCredentials()->withCookie((string) config('session.admin_cookie'), $studentCookie);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    vvAdminGet('/admin/staff')->assertUnauthorized();
});

test('cookie phien staff (vv_admin_session) dat vao ten cookie hoc sinh o host api -> khong dung duoc', function () {
    $teacher = vvStaffUser('teacher');
    $cookie = vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());

    app('auth')->forgetGuards();
    test()->flushSession();
    $response = test()->withCredentials()->withCookie((string) config('session.cookie'), $cookie)
        ->getJson(vvApiUrl('/auth/me'), vvWebHeaders());

    expect($response->status())->toBeIn([401, 403]);
    $response->assertJsonMissingPath('email');
});

test('cookie chua dang nhap voi gia tri ngau nhien o ca hai host -> 401', function () {
    $random = Str::random(40);
    test()->withCredentials()->withCookie((string) config('session.admin_cookie'), $random);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();

    test()->withCookie((string) config('session.cookie'), $random);
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertUnauthorized();
});
