<?php

use App\Support\ProductionConfigGuard;
use Dotenv\Dotenv;

/**
 * T31 QA — `.env.production.example` sau khi thay placeholder bằng giá trị hợp lệ phải qua guard
 * (cả APP_ENV=production và staging). Cùng cách skip với WorkerVideoEnvTest khi không mount infra/.
 */
function qaLoadEnvExample(string $path, array $replace, string $appEnv): array
{
    $vars = Dotenv::parse((string) file_get_contents($path));
    $vars = array_merge($vars, $replace, ['APP_ENV' => $appEnv]);

    $backup = [];
    foreach ($vars as $k => $v) {
        $backup[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
        $_ENV[$k] = $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }

    return [$vars, $backup];
}

function qaRestoreEnv(array $backup): void
{
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

function qaRunGuardWithEnv(array $overrides, string $appEnv): array
{
    $path = base_path('../infra/production/.env.production.example');
    $valid = [
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'TRUSTED_PROXIES' => '10.0.0.1,10.0.0.2',
        'INTERNAL_API_TOKEN' => str_repeat('a', 64),
        'VIDEOLAB_API_KEY' => str_repeat('a', 64),
        'VIDEOLAB_TOKEN_KEY' => str_repeat('b', 64),
        'VIDEOLAB_WEBHOOK_SECRET' => str_repeat('c', 64),
        'MOMO_ENDPOINT' => 'https://payment.momo.vn/v2/gateway/api/create',
    ];
    [$vars, $backup] = qaLoadEnvExample($path, array_merge($valid, $overrides), $appEnv);

    try {
        $loaded = [];
        foreach (['app', 'session', 'sanctum', 'captcha', 'payments', 'video', 'videolab', 'internal', 'auth', 'features'] as $name) {
            $loaded[$name] = require config_path("{$name}.php");
        }
        config($loaded);
        app()->detectEnvironment(fn () => $appEnv);

        $error = null;
        try {
            (new ProductionConfigGuard)->check();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        return [$error, [
            'secure' => config('session.secure'),
            'videolab' => config('videolab.enabled'),
            'gateways' => config('payments.enabled_gateways'),
            'captcha' => config('captcha.driver'),
            'relaxed' => config('auth.otp.e2e_relaxed'),
            'debug' => config('app.debug'),
        ]];
    } finally {
        qaRestoreEnv($backup);
    }
}

$skipReason = 'Container php chỉ mount backend/: chạy bằng `docker run` có mount infra/production (xem docs/ops/production-checklist.md §7).';
$missing = ! file_exists(dirname(__DIR__, 4).'/infra/production/.env.production.example');

test('env mau production, sau khi thay placeholder hop le, qua guard', function (string $env) {
    [$error, $cfg] = qaRunGuardWithEnv([], $env);

    expect($error)->toBeNull()
        ->and($cfg['secure'])->toBeTrue()
        ->and($cfg['videolab'])->toBeTrue()
        ->and($cfg['captcha'])->toBe('turnstile')
        ->and($cfg['relaxed'])->toBeFalse()
        ->and($cfg['debug'])->toBeFalse();
})->with(['production', 'staging'])->skip($missing, $skipReason);

test('env mau production giu nguyen placeholder (khoa rong, TRUSTED_PROXIES dang <IP>) thi guard chan', function () {
    // Thieu khoa VideoLab / token noi bo: phai bi chan, khong the vo tinh deploy file mau nguyen ban.
    [$error] = qaRunGuardWithEnv(['VIDEOLAB_API_KEY' => ''], 'production');
    expect($error)->toContain('VIDEOLAB_API_KEY');

    [$error] = qaRunGuardWithEnv(['INTERNAL_API_TOKEN' => ''], 'production');
    expect($error)->toContain('INTERNAL_API_TOKEN');
})->skip($missing, $skipReason);

test('env mau production: PAYMENT_GATEWAYS de trong (MVP) va FEATURE_PAID_CHECKOUT=false', function () {
    [, $cfg] = qaRunGuardWithEnv([], 'production');
    expect($cfg['gateways'])->toBe([]);
})->skip($missing, $skipReason);
