<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Support\AtomicCounter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../T03/helpers.php';
require_once __DIR__.'/../T28/helpers.php';

/**
 * GL-A2 (T03-M1 / T28-1) — chạm ngưỡng đăng nhập sai thì ĐÒI captcha (Turnstile) thay vì khoá tài khoản; trần cứng → 429.
 * Dùng request thô (không qua helper T03/T28, vốn luôn gửi captcha và hạ trần về 10).
 */
// Mặc định nới limiter lượt captcha bị từ chối (10/phút theo cặp, 120/phút theo IP) để các test khác không chạm; test riêng đặt lại.
beforeEach(fn () => config(['auth.login.captcha_rejects_per_minute' => 1000, 'auth.login.captcha_rejects_per_minute_ip' => 1000]));

function glStudent(): User
{
    return User::factory()->create([
        'email' => 'hs@example.com',
        'phone' => '0912345678',
        'password' => Hash::make('dung-mat-khau-1'),
    ]);
}

function glLogin(string $login = 'hs@example.com', string $password = 'sai', ?string $captcha = null, array $headers = [])
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->postJson(vvApiUrl('/auth/login'), array_filter([
        'login' => $login,
        'password' => $password,
        'captcha_token' => $captcha,
    ], static fn ($v) => $v !== null), vvWebHeaders($headers));
}

function glAdminLogin(string $login, string $password = 'sai', ?string $captcha = null, array $headers = [])
{
    app('auth')->forgetGuards();

    return test()->postJson(vvAdminUrl('/admin/auth/login'), array_filter([
        'login' => $login,
        'password' => $password,
        'captcha_token' => $captcha,
    ], static fn ($v) => $v !== null), vvAdminHeaders($headers));
}

describe('học sinh', function () {
    test('dưới ngưỡng: sai 4 lần chưa đòi captcha (captcha_required=false), lượt 5 đúng mật khẩu vào được không cần captcha', function () {
        glStudent();

        foreach (range(1, 4) as $i) {
            glLogin()->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('captcha_required', false);
        }

        glLogin(password: 'dung-mat-khau-1')->assertOk();
    });

    test('lượt sai thứ 5 báo captcha_required=true cho lần sau; thiếu captcha -> 422 CAPTCHA_REQUIRED kể cả mật khẩu đúng', function () {
        glStudent();

        foreach (range(1, 4) as $i) {
            glLogin()->assertStatus(422);
        }
        glLogin()->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('captcha_required', true);

        glLogin(password: 'dung-mat-khau-1')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAPTCHA_REQUIRED')
            ->assertJsonPath('captcha_required', true)
            ->assertJsonValidationErrors(['captcha_token']);
        $this->assertGuest('web');
    });

    test('captcha sai -> 422 CAPTCHA_INVALID; captcha đúng + mật khẩu đúng -> 200 và reset bộ đếm', function () {
        $user = glStudent();
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }

        glLogin(password: 'dung-mat-khau-1', captcha: 'invalid')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_INVALID')->assertJsonPath('captcha_required', true);
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertOk();

        expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(0);
        glLogin()->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('captcha_required', false);
    });

    test('có captcha nhưng mật khẩu sai: 422 thông điệp chung, KHÔNG khoá, vẫn đăng nhập được khi đúng', function () {
        glStudent();
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }

        foreach (range(1, 20) as $i) {
            glLogin(captcha: 'ok')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('captcha_required', true);
        }

        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertOk();
    });

    test('request bị từ chối vì captcha KHÔNG so mật khẩu và KHÔNG tiêu hao bộ đếm (người ngoài không đẩy tài khoản tới trần bằng request không captcha)', function () {
        $user = glStudent();
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }
        $checks = 0;
        Hash::shouldReceive('check')->andReturnUsing(function () use (&$checks) {
            $checks++;

            return false;
        });

        foreach (range(1, 25) as $i) {
            glLogin(password: 'doan-mo')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        }

        expect($checks)->toBe(0)
            ->and(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(5)
            ->and(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(5);
    });

    test('người ngoài ở IP khác làm đến ngưỡng; người thật ở IP khác vẫn đăng nhập được (qua captcha), không bị 429', function () {
        glStudent();
        foreach (range(1, 6) as $i) {
            glLogin(headers: ['X-Forwarded-For' => '203.0.113.'.$i])->assertStatus(422);
        }

        glLogin(password: 'dung-mat-khau-1', headers: ['X-Forwarded-For' => '198.51.100.77'])
            ->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok', headers: ['X-Forwarded-For' => '198.51.100.77'])->assertOk();
    });

    test('tài khoản không tồn tại hành xử y hệt tài khoản thật ở từng bước (status, code, captcha_required)', function () {
        glStudent();
        $shape = function (string $login): array {
            $out = [];
            foreach (range(1, 6) as $i) {
                $r = glLogin($login);
                $out[] = [$r->status(), $r->json('code'), $r->json('captcha_required')];
            }
            foreach ([null, 'invalid', 'ok'] as $captcha) {
                $r = glLogin($login, 'dung-mat-khau-1', $captcha);
                $out[] = [$r->status(), $r->json('code'), $r->json('captcha_required')];
            }

            return $out;
        };

        $real = $shape('hs@example.com');
        // Mật khẩu đúng + captcha đúng chỉ khác nhau ở bước cuối (200 vs 422 thông điệp chung); các bước trước phải giống hệt.
        $ghost = $shape('khongco@example.com');

        expect(array_slice($ghost, 0, 8))->toBe(array_slice($real, 0, 8))
            ->and($real[8][0])->toBe(200)
            ->and($ghost[8])->toBe([422, 'VALIDATION_ERROR', true]);
    });

    test('cách viết khác của cùng định danh (SĐT/hoa thường/có dấu) dùng chung bộ đếm -> không né được captcha', function () {
        glStudent();
        foreach (['0912345678', '+84912345678', '84912345678', '091 234 5678', '0912.345.678'] as $login) {
            glLogin($login)->assertStatus(422);
        }

        glLogin('HS@Example.com')->assertStatus(422)->assertJsonPath('captcha_required', true);
        glLogin('hs@exámple.com', 'dung-mat-khau-1')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        $this->assertGuest('web');
    });

    test('trần cứng theo tài khoản: có captcha vẫn bị chặn -> 429 + Retry-After (kể cả mật khẩu đúng)', function () {
        config(['auth.login.max_failures_per_account' => 8]);
        glStudent();

        foreach (range(1, 8) as $i) {
            glLogin(captcha: 'ok')->assertStatus(422);
        }

        $r = glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);
        $this->assertGuest('web');
    });

    test('trần IP chỉ đếm lượt KHÔNG kèm captcha: vượt trần thì 429, nhưng lượt có captcha hợp lệ (NAT lớp học) vẫn vào được', function () {
        config(['auth.login.max_failures_per_ip' => 50]);
        glStudent();

        foreach (range(1, 50) as $i) {
            glLogin("nguoi{$i}@example.com")->assertStatus(422);
        }

        // V2-3b: không captcha mà chạm trần IP -> 422 CAPTCHA_REQUIRED (không 429) để người sau NAT giải captcha.
        glLogin('nguoi51@example.com')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED')->assertJsonPath('captcha_required', true);
        glLogin(password: 'dung-mat-khau-1')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertOk();   // có captcha: không bị trần IP
    });

    test('lượt sai CÓ captcha hợp lệ không tính vào bộ đếm IP (chỉ tài khoản)', function () {
        glStudent();
        foreach (range(1, 8) as $i) {
            glLogin(captcha: 'ok')->assertStatus(422);
        }

        expect(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(0)
            ->and(RateLimiter::attempts('login-fail-ip-captcha:127.0.0.1'))->toBe(8);
    });

    test('trần IP mặc định 200/giờ (chờ PO xác nhận)', function () {
        expect(config('auth.login.max_failures_per_ip'))->toBe(200)
            ->and(config('auth.staff.login_max_failures_per_ip'))->toBe(200);
    });

    test('S1: request bị chặn vì trần IP KHÔNG để lại lượt ở bộ đếm tài khoản', function () {
        config(['auth.login.max_failures_per_ip' => 20]);
        $user = glStudent();
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }
        AtomicCounter::add('login-fail-ip:127.0.0.1', 20, 3600);

        foreach (range(1, 10) as $i) {
            glLogin()->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        }

        expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(5);
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertOk();
    });

    test('hitAll tất-cả-hoặc-không: khoá nào chạm trần thì không khoá nào bị cộng', function () {
        AtomicCounter::add('t-b', 1, 60);

        $r = AtomicCounter::hitAll([['t-a', 2], ['t-b', 1]], 60);

        expect($r['blocked'])->toBe('t-b')
            ->and(AtomicCounter::attempts('t-a'))->toBe(0)
            ->and(AtomicCounter::attempts('t-b'))->toBe(1);

        $r = AtomicCounter::hitAll([['t-a', 2], ['t-c', 1]], 60);
        expect($r['blocked'])->toBeNull()->and($r['counts'])->toBe([1, 1]);
    });

    test('R2: lượt bị captcha từ chối (thiếu hoặc sai) có limiter riêng theo IP -> 429', function () {
        config(['auth.login.captcha_rejects_per_minute' => 4, 'auth.login.captcha_rejects_per_minute_ip' => 120]);
        glStudent();
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }

        glLogin(password: 'x')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        glLogin(password: 'x', captcha: 'invalid')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_INVALID');
        glLogin(password: 'x')->assertStatus(422);
        glLogin(password: 'x', captcha: 'invalid')->assertStatus(422);

        glLogin(password: 'x')->assertStatus(429)->assertHeader('Retry-After');
        glLogin(password: 'x', captcha: 'invalid')->assertStatus(429);
        // IP khác không bị ảnh hưởng.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        glLogin(password: 'x')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    });

    test('V2-3a: IP X gửi 30 token sai vào tài khoản A rồi tài khoản B cùng IP gửi token hợp lệ + mật khẩu đúng -> 200', function () {
        glStudent();
        User::factory()->create(['email' => 'b@example.com', 'password' => Hash::make('mat-khau-b-123')]);
        foreach (range(1, 5) as $i) {
            glLogin()->assertStatus(422);
        }

        foreach (range(1, 30) as $i) {
            $r = glLogin(password: 'x', captcha: 'invalid');
            expect($r->status())->toBeIn([422, 429]);
        }

        glLogin('b@example.com', 'mat-khau-b-123', 'ok')->assertOk();
    });

    test('V2-3a: limiter lượt captcha bị từ chối theo IP (cao) vẫn chặn quét nhiều tài khoản', function () {
        config(['auth.login.captcha_rejects_per_minute' => 100, 'auth.login.captcha_rejects_per_minute_ip' => 12]);

        foreach (range(1, 12) as $i) {
            glLogin("q{$i}@example.com", 'x', 'invalid')->assertStatus(422);
        }
        glLogin('q13@example.com', 'x', 'invalid')->assertStatus(429);
    });

    test('V2-2: trần IP riêng cho lượt sai CÓ captcha -> 429, IP khác vẫn đăng nhập được', function () {
        config(['auth.login.max_captcha_failures_per_ip' => 25]);
        glStudent();
        foreach (range(1, 25) as $i) {
            glLogin("c{$i}@example.com", 'x', 'ok')->assertStatus(422);
        }

        glLogin('c26@example.com', 'x', 'ok')->assertStatus(429);
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
        glLogin(password: 'dung-mat-khau-1', captcha: 'ok')->assertOk();
    });

    test('ngưỡng và trần đọc từ config', function () {
        config(['auth.login.captcha_threshold' => 2]);
        glStudent();

        glLogin()->assertStatus(422)->assertJsonPath('captcha_required', false);
        glLogin()->assertStatus(422)->assertJsonPath('captcha_required', true);
        glLogin()->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    });

    test('mật khẩu đúng nhưng sai cổng (giáo viên ở trang học sinh) vẫn WRONG_PORTAL sau cổng captcha', function () {
        User::factory()->teacher()->create(['email' => 'gv@example.com']);
        foreach (range(1, 5) as $i) {
            glLogin('gv@example.com')->assertStatus(422);
        }

        glLogin('gv@example.com', 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        glLogin('gv@example.com', 'password', 'ok')->assertStatus(403)->assertJsonPath('code', 'WRONG_PORTAL');
    });

    test('captchaNeededAfter: chạm ngưỡng thì true', function () {
        expect(LoginService::captchaNeededAfter(4))->toBeFalse()
            ->and(LoginService::captchaNeededAfter(5))->toBeTrue()
            ->and(LoginService::captchaNeededAfter(1, 1))->toBeTrue();
    });
});

describe('quản trị', function () {
    test('dưới ngưỡng không captcha; từ ngưỡng thiếu/sai -> CAPTCHA_REQUIRED/INVALID; đúng -> vào; không lộ tài khoản có tồn tại', function () {
        $teacher = vvStaffUser('teacher');
        $email = (string) $teacher->email;

        foreach (range(1, 4) as $i) {
            glAdminLogin($email)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('captcha_required', false);
        }
        glAdminLogin($email)->assertStatus(422)->assertJsonPath('captcha_required', true);

        glAdminLogin($email, 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        glAdminLogin($email, 'password', 'invalid')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_INVALID');
        glAdminLogin($email, 'password', 'ok')->assertOk()->assertJsonPath('mfa_required', false);

        // Reset khi đúng.
        expect(RateLimiter::attempts('staff-login-fail:u:'.$teacher->getKey()))->toBe(0);
    });

    test('tài khoản quản trị không tồn tại hành xử y hệt', function () {
        $email = (string) vvStaffUser('teacher')->email;
        $shape = function (string $login): array {
            $out = [];
            foreach (range(1, 6) as $i) {
                $r = glAdminLogin($login);
                $out[] = [$r->status(), $r->json('code'), $r->json('captcha_required')];
            }
            foreach ([null, 'invalid'] as $captcha) {
                $r = glAdminLogin($login, 'password', $captcha);
                $out[] = [$r->status(), $r->json('code'), $r->json('captcha_required')];
            }

            return $out;
        };

        expect($shape('khongco@example.com'))->toBe($shape($email));
    });

    test('admin qua cổng captcha vẫn phải qua MFA: 200 mfa_required=true, phiên chưa dùng được API quản trị', function () {
        $admin = vvStaffUser('admin');
        $email = (string) $admin->email;
        foreach (range(1, 5) as $i) {
            glAdminLogin($email)->assertStatus(422);
        }

        glAdminLogin($email, 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        $r = glAdminLogin($email, 'password', 'ok')->assertOk()->assertJsonPath('mfa_required', true);
        vvAdminFollow($r);

        test()->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders())->assertStatus(403)->assertJsonPath('code', 'MFA_REQUIRED');
    });

    test('trần cứng theo tài khoản staff: có captcha vẫn 429', function () {
        config(['auth.staff.login_max_failures_per_account' => 7]);
        $email = (string) vvStaffUser('teacher')->email;

        foreach (range(1, 7) as $i) {
            glAdminLogin($email, 'sai', 'ok')->assertStatus(422);
        }

        glAdminLogin($email, 'password', 'ok')->assertStatus(429)->assertHeader('Retry-After');
    });

    test('audit: KHÔNG ghi từng lượt captcha_required; captcha_invalid tối đa 1 dòng/10 phút/tài khoản', function () {
        $teacher = vvStaffUser('teacher');
        $email = (string) $teacher->email;
        foreach (range(1, 5) as $i) {
            glAdminLogin($email)->assertStatus(422);
        }
        $reasons = fn () => AuditLog::query()->where('action', 'staff.login_failed')->where('subject_id', $teacher->id)->orderBy('id')->get()
            ->map(fn ($l) => $l->changes['reason'] ?? null)->all();
        $before = count($reasons());

        foreach (range(1, 20) as $i) {
            glAdminLogin($email, 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        }
        expect(count($reasons()))->toBe($before);

        foreach (range(1, 3) as $i) {
            glAdminLogin($email, 'password', 'invalid')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_INVALID');
        }
        expect(array_slice($reasons(), $before))->toBe(['captcha_invalid']);
    });

    test('quản trị: lượt có captcha không tính bộ đếm IP; không captcha vượt trần IP -> 429', function () {
        config(['auth.staff.login_max_failures_per_ip' => 20]);
        $teacher = vvStaffUser('teacher');
        foreach (range(1, 20) as $i) {
            glAdminLogin("khongco{$i}@example.com")->assertStatus(422);
        }

        glAdminLogin((string) $teacher->email, 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED')->assertJsonPath('captcha_required', true);
        glAdminLogin((string) $teacher->email, 'password', 'ok')->assertOk();
    });

    test('quản trị: 429 vì trần IP không để lại lượt ở bộ đếm tài khoản (S1)', function () {
        config(['auth.staff.login_max_failures_per_ip' => 20]);
        $teacher = vvStaffUser('teacher');
        AtomicCounter::add('staff-login-fail-ip:127.0.0.1', 20, 3600);

        foreach (range(1, 5) as $i) {
            glAdminLogin((string) $teacher->email)->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
        }

        expect(RateLimiter::attempts('staff-login-fail:u:'.$teacher->getKey()))->toBe(0);
    });
});
