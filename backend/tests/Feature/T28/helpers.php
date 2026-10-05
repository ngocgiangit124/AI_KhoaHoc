<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T04/helpers.php';

function vvAdminUrl(string $path): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1'.$path;
}

/** @return array<string, string> */
function vvAdminHeaders(array $extra = []): array
{
    return array_merge(['Origin' => config('app.admin_url')], $extra);
}

/**
 * Nhân sự test: mật khẩu `password` (factory), không buộc đổi mật khẩu.
 *
 * @param  'teacher'|'admin'|'pageManager'  $state
 */
function vvStaffUser(string $state = 'teacher', array $attrs = []): User
{
    $user = User::factory()->{$state}()->create($attrs);

    // `must_change_password` không nằm trong $fillable (S17) nên đặt qua forceFill.
    $user->forceFill(['must_change_password' => $attrs['must_change_password'] ?? false])->save();

    return $user->fresh();
}

/** Gọi login quản trị (chưa gắn cookie phiên cho request sau — dùng vvAdminFollow). */
function vvAdminLogin(string $login, string $password = 'password', array $headers = [], array $extra = []): TestResponse
{
    return test()->postJson(vvAdminUrl('/admin/auth/login'), array_merge([
        'login' => $login,
        'password' => $password,
    ], $extra), vvAdminHeaders($headers));
}

/** Trình duyệt "giữ" cookie `vv_admin_session` từ response cho các request sau. */
function vvAdminFollow(TestResponse $response): string
{
    $cookie = $response->getCookie((string) config('session.admin_cookie'));
    expect($cookie)->not->toBeNull('response không Set-Cookie phiên admin');

    vvAdminUseCookie($cookie->getValue());

    return $cookie->getValue();
}

/** Chuyển sang "trình duyệt" khác (guard của app test dùng chung nên phải quên user cũ). */
function vvAdminUseCookie(string $value): void
{
    app('auth')->forgetGuards();
    test()->withCredentials()->withCookie((string) config('session.admin_cookie'), $value);
}

function vvAdminGet(string $path, array $headers = []): TestResponse
{
    return test()->getJson(vvAdminUrl($path), vvAdminHeaders($headers));
}

/**
 * Đăng nhập thật qua API (kể cả bước MFA nếu cần) và giữ cookie phiên. Dùng cho test các route quản trị
 * ở task sau: `vvStaffLogin($admin)` rồi gọi `vvAdminGet(...)`.
 */
function vvStaffLogin(User $user, string $password = 'password', ?VvCapturingOtpSender $otp = null): void
{
    $otp ??= vvFakeOtp();

    $response = vvAdminLogin((string) $user->email, $password)->assertOk();
    vvAdminFollow($response);

    if ($response->json('mfa_required') === true) {
        app('auth')->forgetGuards();
        $verify = test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())->assertOk();
        vvAdminFollow($verify);
    }
}
