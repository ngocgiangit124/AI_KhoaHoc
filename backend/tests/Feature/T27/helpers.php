<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Pest\TestSuite;

require_once __DIR__.'/../T04/helpers.php';

/**
 * "Trình duyệt" giả cho T27: giữ cookie `vv_session` + device id riêng; mỗi request mô phỏng 1 process mới
 * (chỉ cookie mang trạng thái) — cần cho kịch bản A/B 2 phiên thật qua HTTP.
 */
final class VvPwBrowser
{
    public ?string $cookie = null;

    public string $device;

    public bool $sendDevice = true;

    public function __construct()
    {
        $this->device = (string) Str::uuid();
    }

    /** @param array<string, mixed> $payload */
    public function call(string $method, string $path, array $payload = [])
    {
        $test = TestSuite::getInstance()->test;
        app('auth')->forgetGuards();
        $test->flushSession();
        $test->flushHeaders();
        (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);
        $test->withCredentials();

        if ($this->cookie !== null) {
            $test->withCookie(config('session.cookie'), $this->cookie);
        }

        $response = $test->json($method, vvApiUrl($path), $payload, vvWebHeaders($this->sendDevice ? ['X-Device-Id' => $this->device] : []));

        $set = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        if ($set !== null) {
            $this->cookie = $response->getCookie(config('session.cookie'))?->getValue() ?? $this->cookie;
        }

        return $response;
    }

    public function login(string $password = 'mat-khau-cu-1')
    {
        return $this->call('POST', '/auth/login', ['login' => 'hs@example.com', 'password' => $password, 'device_id' => $this->device]);
    }

    public function me()
    {
        return $this->call('GET', '/auth/me');
    }

    public function changePassword(string $current, string $new)
    {
        return $this->call('PUT', '/auth/password', [
            'current_password' => $current, 'password' => $new, 'password_confirmation' => $new,
        ]);
    }
}

function vvPwStudent(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'hs@example.com',
        'phone' => '0912345678',
        'password' => Hash::make('mat-khau-cu-1'),
        // H1: mã đặt lại chỉ gửi/nhận qua email đã xác thực.
        'email_verified_at' => now(),
    ], $attrs));
}

function vvForgot(array $payload = [], array $headers = [])
{
    return test()->postJson(vvApiUrl('/auth/password/forgot'), array_merge(['login' => 'hs@example.com', 'captcha_token' => 'ok'], $payload), vvWebHeaders($headers));
}

function vvReset(string $code, array $payload = [])
{
    return test()->postJson(vvApiUrl('/auth/password/reset'), array_merge([
        'login' => 'hs@example.com', 'code' => $code, 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
    ], $payload), vvWebHeaders());
}
