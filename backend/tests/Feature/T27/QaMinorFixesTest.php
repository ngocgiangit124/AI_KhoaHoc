<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\PasswordService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/helpers.php';

// QA gom sửa lỗi nhỏ (minor-fixes-1): phiên cũ host api, OTP_EXPIRED chống dò, OTP relaxed ở production.

beforeEach(fn () => Cache::flush());

function vvQaIssueResetCode(User $user): string
{
    $sender = vvFakeOtp();
    (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => $user->email, 'captcha_token' => 'ok'])->assertStatus(202);
    Cache::flush();

    return $sender->lastCode();
}

test('QA minor-fixes: api phien cu sau reset mat khau: csrf-token + login 200, route can dang nhap 401 SESSION_REVOKED', function () {
    $user = vvPwStudent();
    $a = new VvPwBrowser;
    $a->login()->assertOk();
    $a->me()->assertOk();

    $code = vvQaIssueResetCode($user);
    (new VvPwBrowser)->call('POST', '/auth/password/reset', [
        'login' => 'hs@example.com', 'code' => $code, 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
    ])->assertOk();

    $old = $a->cookie;
    $a->call('GET', '/csrf-token')->assertOk();
    $a->cookie = $old;
    $a->call('POST', '/auth/login', ['login' => 'hs@example.com', 'password' => 'mat-khau-moi-2', 'device_id' => $a->device])->assertOk();

    $stale = new VvPwBrowser;
    $stale->cookie = $old;
    $stale->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    // Cookie cũ + csrf-token ngay sau khi nhận 401 vẫn 200.
    $stale->call('GET', '/csrf-token')->assertOk();
});

test('QA minor-fixes: reset - khong ton tai / bi khoa / khong co ma: cung 422 OTP_EXPIRED, cung message', function () {
    vvPwStudent();
    User::factory()->create(['email' => 'khoa@example.com', 'phone' => '0911111111', 'status' => UserStatus::Locked]);
    User::factory()->create(['email' => 'khongma@example.com', 'phone' => '0922222222']);

    $results = [];
    foreach (['khongco@example.com', 'khoa@example.com', 'khongma@example.com', 'hs@example.com'] as $login) {
        Cache::flush();
        $r = vvReset('123456', ['login' => $login])->assertStatus(422);
        $results[$login] = [$r->json('code'), $r->json('message'), $r->json('errors')];
    }

    expect(array_unique(array_map('json_encode', $results)))->toHaveCount(1);
    expect(array_values($results)[0][0])->toBe('OTP_EXPIRED')->and(array_values($results)[0][2])->toHaveKey('code');
});

test('QA minor-fixes: do chenh thoi gian phan hoi reset (ghi nhan, khong chat)', function () {
    vvPwStudent();
    User::factory()->create(['email' => 'khoa@example.com', 'phone' => '0911111111', 'status' => UserStatus::Locked]);
    $svc = app(PasswordService::class);
    $median = function (string $login) use ($svc): float {
        $times = [];
        for ($i = 0; $i < 25; $i++) {
            $t = hrtime(true);
            try {
                $svc->reset($login, '123456', 'mat-khau-moi-2');
            } catch (Throwable) {
            }
            $times[] = (hrtime(true) - $t) / 1e6;
        }
        sort($times);

        return $times[12];
    };
    $med = ['khongco' => $median('khongco@example.com'), 'khoa' => $median('khoa@example.com'), 'khongma' => $median('hs@example.com')];
    fwrite(STDERR, "\n[QA timing ms median/25] ".json_encode($med)."\n");

    // Lỏng: không chênh quá 5 lần giữa nhánh nhanh nhất và chậm nhất.
    expect(max($med) / max(min($med), 0.01))->toBeLessThan(5);
});

test('QA minor-fixes: production + AUTH_OTP_E2E_RELAXED=true van 1/phut, 5/gio (forgot)', function () {
    $prod = (function () {
        putenv('APP_ENV=production');
        putenv('AUTH_OTP_E2E_RELAXED=true');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        $_ENV['AUTH_OTP_E2E_RELAXED'] = $_SERVER['AUTH_OTP_E2E_RELAXED'] = 'true';
        try {
            return (require base_path('config/auth.php'))['otp'];
        } finally {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
            putenv('AUTH_OTP_E2E_RELAXED');
            unset($_ENV['AUTH_OTP_E2E_RELAXED'], $_SERVER['AUTH_OTP_E2E_RELAXED']);
        }
    })();
    config(['auth.otp' => $prod]);
    $sender = vvFakeOtp();
    vvPwStudent();

    vvForgot()->assertStatus(202);
    // Lần 2 trong cùng phút: không được nới 1000/phút. Chấp nhận 429 hoặc 202 im lặng (không gửi thêm mã).
    vvForgot();
    expect($sender->sent)->toHaveCount(1);

    // 5/giờ: qua cooldown 60s mỗi lần, thử 8 lần trong 1 giờ.
    foreach (range(1, 8) as $i) {
        $this->travel(61)->seconds();
        vvForgot();
    }
    expect(count($sender->sent))->toBeLessThanOrEqual(5)->and(count($sender->sent))->toBeGreaterThan(1);
});
