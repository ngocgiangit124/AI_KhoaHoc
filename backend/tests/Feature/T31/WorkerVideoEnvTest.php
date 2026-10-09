<?php

use App\Support\ProductionConfigGuard;
use Dotenv\Dotenv;

/**
 * R1 (review T31) — guard chạy cả trong `queue:work`, nên bộ env của file mẫu worker-video phải qua được guard
 * ở APP_ENV=production. Nạp đúng file mẫu vào env, đọc lại các file config thật rồi chạy guard.
 */
test('env mau worker-video o APP_ENV=production khong lam guard nem loi', function () {
    $path = base_path('../infra/production/.env.worker-video.example');
    $vars = Dotenv::parse((string) file_get_contents($path));
    expect($vars)->toHaveKey('APP_ENV', 'production');
    // Ép khoá Turnstile không phải khoá test (.env local có 1x0000…); worker không dùng captcha nhưng guard chặn khoá test ở mọi tiến trình.
    $vars = array_merge($vars, ['TURNSTILE_SITE_KEY' => '0x4AAAAAAAsite', 'TURNSTILE_SECRET' => '0x4AAAAAAAsecret', 'CACHE_LIMITER' => 'redis-limiter']);

    $backup = [];
    foreach ($vars as $k => $v) {
        $backup[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
        $_ENV[$k] = $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }

    try {
        $loaded = [];
        foreach (['app', 'session', 'sanctum', 'captcha', 'payments', 'video', 'videolab', 'internal', 'auth', 'features', 'database', 'mail', 'services', 'cache'] as $name) {
            $loaded[$name] = require config_path("{$name}.php");
        }
        config($loaded);
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
        expect(config('session.secure'))->toBeTrue()
            ->and(config('videolab.enabled'))->toBeFalse();
    } finally {
        foreach ($backup as $k => [$e, $s, $g]) {
            $e === null ? $_ENV[$k] = null : $_ENV[$k] = $e;
            if ($e === null) {
                unset($_ENV[$k]);
            }
            if ($s === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $s;
            }
            $g === false ? putenv($k) : putenv("{$k}={$g}");
        }
    }
})->skip(
    ! file_exists(dirname(__DIR__, 4).'/infra/production/.env.worker-video.example'),
    'Container php chỉ mount backend/: chạy bằng `docker run` có mount infra/production (xem docs/ops/production-checklist.md §7).'
);
