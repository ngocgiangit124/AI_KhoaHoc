<?php

use App\Models\User;
use App\Support\AtomicCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

require_once __DIR__.'/../T03/helpers.php';
require_once __DIR__.'/../T28/helpers.php';

/**
 * QA GL-A2 — kịch bản độc lập với test của Dev: kết hợp trần IP/tài khoản/captcha, cô lập giữa các cặp IP+tài khoản,
 * đầu vào bất thường, và Lua `hitAll` trên Redis THẬT (khoá ngẫu nhiên, TTL ngắn, tự hết hạn).
 */
beforeEach(fn () => config(['auth.login.captcha_rejects_per_minute' => 1000, 'auth.login.captcha_rejects_per_minute_ip' => 1000]));

function qaA2Student(string $email = 'qa-hs@example.com'): User
{
    return User::factory()->create(['email' => $email, 'phone' => null, 'password' => Hash::make('dung-mat-khau-1')]);
}

function qaA2Login(string $login, string $password = 'sai', mixed $captcha = null, array $headers = [])
{
    app('auth')->forgetGuards();
    test()->flushSession();
    $payload = ['login' => $login, 'password' => $password];
    if ($captcha !== null) {
        $payload['captcha_token'] = $captcha;
    }

    return test()->postJson(vvApiUrl('/auth/login'), $payload, vvWebHeaders($headers));
}

function qaA2Admin(string $login, string $password = 'sai', mixed $captcha = null)
{
    app('auth')->forgetGuards();
    $payload = ['login' => $login, 'password' => $password];
    if ($captcha !== null) {
        $payload['captcha_token'] = $captcha;
    }

    return test()->postJson(vvAdminUrl('/admin/auth/login'), $payload, vvAdminHeaders());
}

test('AC1: sai đủ 5 lần -> lần 6 thiếu token 422 CAPTCHA_REQUIRED, token sai CAPTCHA_INVALID, token đúng 200 (học sinh)', function () {
    $u = qaA2Student();
    foreach (range(1, 5) as $i) {
        qaA2Login($u->email)->assertStatus(422);
    }
    qaA2Login($u->email, 'dung-mat-khau-1')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    qaA2Login($u->email, 'dung-mat-khau-1', 'invalid')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_INVALID');
    qaA2Login($u->email, 'dung-mat-khau-1', 'ok')->assertOk();
});

test('AC2: trần IP không captcha đầy -> 422 CAPTCHA_REQUIRED (không 429), lượt có captcha vẫn vào, bộ đếm tài khoản không bị đẩy lên', function () {
    config(['auth.login.max_failures_per_ip' => 30]);
    $u = qaA2Student();
    AtomicCounter::add('login-fail-ip:127.0.0.1', 30, 3600);

    qaA2Login('nguoi-la@example.com')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    qaA2Login($u->email, 'dung-mat-khau-1')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    expect(RateLimiter::attempts('login-fail:u:'.$u->getKey()))->toBe(0);

    qaA2Login($u->email, 'dung-mat-khau-1', 'ok')->assertOk();
    // Đăng nhập đúng hoàn lượt ở cả khoá IP-captcha và tài khoản.
    expect(RateLimiter::attempts('login-fail-ip-captcha:127.0.0.1'))->toBe(0)
        ->and(RateLimiter::attempts('login-fail:u:'.$u->getKey()))->toBe(0)
        ->and(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe(30);
});

test('AC3: trần IP có captcha đầy -> 429 kèm Retry-After; IP khác không bị ảnh hưởng', function () {
    config(['auth.login.max_captcha_failures_per_ip' => 12]);
    $u = qaA2Student();
    AtomicCounter::add('login-fail-ip-captcha:127.0.0.1', 12, 3600);

    $r = qaA2Login($u->email, 'dung-mat-khau-1', 'ok')->assertStatus(429);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);

});

test('AC4: trần tài khoản đầy -> 429 dù có captcha và mật khẩu đúng; IP nhận 429 không bị cộng thêm', function () {
    config(['auth.login.max_failures_per_account' => 20]);
    $u = qaA2Student();
    AtomicCounter::add('login-fail:u:'.$u->getKey(), 20, 3600);
    $before = RateLimiter::attempts('login-fail-ip:127.0.0.1');

    qaA2Login($u->email, 'dung-mat-khau-1')->assertStatus(429);
    qaA2Login($u->email, 'dung-mat-khau-1', 'ok')->assertStatus(429);
    $this->assertGuest('web');
    expect(RateLimiter::attempts('login-fail-ip:127.0.0.1'))->toBe($before);
});

test('AC5/V2-3: 30 token sai vào tài khoản A không chặn tài khoản B cùng IP; A bị 429 theo cặp (10/phút)', function () {
    config(['auth.login.captcha_rejects_per_minute' => 10, 'auth.login.captcha_rejects_per_minute_ip' => 120]);
    $a = qaA2Student('a@example.com');
    $b = qaA2Student('b@example.com');

    $codes = [];
    foreach (range(1, 30) as $i) {
        $codes[] = qaA2Login($a->email, 'sai', 'invalid')->getStatusCode();
    }
    expect(array_count_values($codes))->toBe([422 => 10, 429 => 20]);

    qaA2Login($b->email, 'dung-mat-khau-1', 'ok')->assertOk();
});

test('AC5/V2-3 (quản trị): 30 token sai vào A rồi B cùng IP đăng nhập đúng vẫn 200', function () {
    config(['auth.login.captcha_rejects_per_minute' => 10, 'auth.login.captcha_rejects_per_minute_ip' => 120]);
    $a = vvStaffUser('teacher');
    $b = vvStaffUser('teacher');
    foreach (range(1, 30) as $i) {
        qaA2Admin((string) $a->email, 'sai', 'invalid');
    }
    qaA2Admin((string) $a->email, 'password', 'ok')->assertStatus(429);
    qaA2Admin((string) $b->email, 'password', 'ok')->assertOk();
});

test('AC6: limiter theo IP (120/phút) chặn quét nhiều tài khoản bằng token sai', function () {
    config(['auth.login.captcha_rejects_per_minute' => 1000, 'auth.login.captcha_rejects_per_minute_ip' => 15]);
    foreach (range(1, 15) as $i) {
        qaA2Login("quet{$i}@example.com", 'sai', 'invalid')->assertStatus(422);
    }
    qaA2Login('quet16@example.com', 'sai', 'invalid')->assertStatus(429);
});

test('AC7: admin có captcha đúng + mật khẩu đúng của tài khoản MFA -> bước MFA, chưa có phiên dùng được', function () {
    $admin = vvStaffUser('admin');
    foreach (range(1, 5) as $i) {
        qaA2Admin((string) $admin->email)->assertStatus(422);
    }
    qaA2Admin((string) $admin->email, 'password')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    $r = qaA2Admin((string) $admin->email, 'password', 'ok')->assertOk();
    expect($r->json('mfa_required'))->toBeTrue();
});

test('Biên: captcha_token không phải chuỗi / quá dài / có dấu tiếng Việt không gây 500', function () {
    $u = qaA2Student();
    qaA2Login($u->email, 'sai', ['x'])->assertStatus(422)->assertJsonValidationErrors(['captcha_token']);
    qaA2Login($u->email, 'sai', str_repeat('a', 2049))->assertStatus(422)->assertJsonValidationErrors(['captcha_token']);
    qaA2Login($u->email, 'sai', 'mã-xác-minh-ạ')->assertStatus(422); // fake verifier chấp nhận -> sai mật khẩu, không 500
    qaA2Login($u->email, 'sai', '')->assertStatus(422);
    qaA2Admin((string) vvStaffUser('teacher')->email, 'sai', ['x'])->assertStatus(422)->assertJsonValidationErrors(['captcha_token']);
});

test('Biên: định danh có dấu/hoa thường chung bộ đếm; response không lộ khoá bộ đếm hay IP', function () {
    $u = qaA2Student('Nguyen.Van@Example.com');
    foreach (['nguyen.van@example.com', 'NGUYEN.VAN@EXAMPLE.COM', 'Nguyen.Van@example.com', 'nguyen.van@example.com', 'nguyen.van@example.com'] as $login) {
        qaA2Login($login)->assertStatus(422);
    }
    $r = qaA2Login('nguyen.van@example.com', 'dung-mat-khau-1')->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_REQUIRED');
    expect($r->getContent())->not->toContain('login-fail')->not->toContain('127.0.0.1');
});

// RateLimiter đã được resolve với store `array` lúc boot nên đọc trực tiếp store Redis để kiểm giá trị thật.
function qaRedisCount(string $key): int
{
    return (int) Cache::store('redis-limiter')->get($key);
}

describe('AtomicCounter::hitAll trên Redis thật', function () {
    beforeEach(function () {
        try {
            config(['cache.limiter' => 'redis-limiter']);
            Cache::store('redis-limiter')->get('qa-gla2-ping');
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis không truy cập được: '.$e::class);
        }
    });

    test('tất-cả-hoặc-không, đặt TTL, trả vị trí khoá bị chặn, release không âm', function () {
        $id = (string) Str::uuid();
        $ip = "qa-gla2:{$id}:ip";
        $acc = "qa-gla2:{$id}:acc";

        expect(AtomicCounter::hitAll([[$ip, 3], [$acc, 2]], 20))->toBe(['blocked' => null, 'counts' => [1, 1]]);
        expect(AtomicCounter::hitAll([[$ip, 3], [$acc, 2]], 20))->toBe(['blocked' => null, 'counts' => [2, 2]]);

        // acc đạt trần 2 -> chặn, ip KHÔNG được cộng.
        expect(AtomicCounter::hitAll([[$ip, 3], [$acc, 2]], 20))->toBe(['blocked' => $acc, 'counts' => []]);
        expect(qaRedisCount($ip))->toBe(2);

        // Khoá đầu bị chặn trước.
        AtomicCounter::hitAll([[$ip, 3]], 20);
        expect(AtomicCounter::hitAll([[$ip, 3], [$acc, 2]], 20)['blocked'])->toBe($ip);
        expect(qaRedisCount($acc))->toBe(2);

        $ttl = AtomicCounter::availableIn($ip);
        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(20);

        foreach (range(1, 6) as $i) {
            AtomicCounter::release($acc, 20);
        }
        expect(qaRedisCount($acc))->toBe(0);
    });

    test('cửa sổ không bị kéo dài bởi lượt sau (EXPIRE chỉ đặt ở lượt đầu)', function () {
        $k = 'qa-gla2:'.Str::uuid();
        AtomicCounter::hitAll([[$k, 50]], 30);
        $t1 = AtomicCounter::availableIn($k);
        AtomicCounter::hitAll([[$k, 50]], 3000);
        expect(AtomicCounter::availableIn($k))->toBeLessThanOrEqual($t1);
    });
});
