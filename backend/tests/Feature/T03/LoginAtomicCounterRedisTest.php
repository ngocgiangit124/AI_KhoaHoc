<?php

use App\Models\User;
use App\Support\AtomicCounter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/LoginTest.php';

// QA minor-fixes-2 (tai kiem BUG-1): nhanh Lua tren Redis THAT phai dung cung khoa/prefix/TTL voi RateLimiter.

/** Chuyển limiter sang Redis thật với prefix riêng `racetest-qa-*` (không đụng khoá dev). */
function vvUseRedisLimiter(): string
{
    $prefix = 'racetest-qa-'.bin2hex(random_bytes(4)).'-';
    config(['cache.limiter' => 'redis-limiter', 'cache.prefix' => $prefix]);
    app('cache')->forgetDriver('redis-limiter');
    $limiters = (new ReflectionProperty(Illuminate\Cache\RateLimiter::class, 'limiters'))->getValue(app(Illuminate\Cache\RateLimiter::class));
    app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
    $new = app(Illuminate\Cache\RateLimiter::class);
    (new ReflectionProperty($new, 'limiters'))->setValue($new, $limiters);
    Facade::clearResolvedInstance('Illuminate\Cache\RateLimiter');

    return $prefix;
}

test('Redis that: AtomicCounter va RateLimiter doc/xoa cung 1 khoa; TTL ~3600; khoa co dau duoc clean giong nhau', function () {
    vvUseRedisLimiter();
    $key = 'login-fail:a:'.'đỗ.văn@exámple.com';

    try {
        foreach (range(1, 3) as $i) {
            expect(AtomicCounter::hit($key, 3600))->toBe($i);
        }
        expect((int) RateLimiter::attempts($key))->toBe(3)
            ->and(AtomicCounter::availableIn($key))->toBeBetween(3590, 3600)
            // Ghi nhận: nhánh Lua không tạo khoá `:timer`, nên RateLimiter::availableIn() trả 0 (code chỉ dùng AtomicCounter::availableIn).
            ->and(RateLimiter::availableIn($key))->toBe(0);

        AtomicCounter::release($key, 3600);
        expect((int) RateLimiter::attempts($key))->toBe(2);

        RateLimiter::clear($key);
        expect(AtomicCounter::attempts($key))->toBe(0);
        AtomicCounter::release($key, 3600); // không xuống dưới 0
        expect(AtomicCounter::attempts($key))->toBe(0);
    } finally {
        RateLimiter::clear($key);
    }
});

test('Redis that: 10 luot sai -> luot 11 (mat khau dung) 429 kem Retry-After > 0; dang nhap thanh cong xoa bo dem', function () {
    vvUseRedisLimiter();
    $user = vvStudent();
    $acct = 'login-fail:u:'.$user->getKey();
    $ip = 'login-fail-ip:127.0.0.1';

    try {
        foreach (range(1, 10) as $i) {
            vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
            vvResetClient();
        }
        $r = vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(429);
        expect((int) $r->headers->get('Retry-After'))->toBeBetween(1, 3600);

        RateLimiter::clear($acct);
        RateLimiter::clear($ip);
        vvResetClient();

        vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        vvResetClient();
        vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
        expect((int) RateLimiter::attempts($acct))->toBe(0)
            ->and(User::query()->count())->toBe(1);
    } finally {
        RateLimiter::clear($acct);
        RateLimiter::clear($ip);
    }
});
