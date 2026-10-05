<?php

/** @return array<string, mixed> */
function vvLoadAuthConfig(string $env, string $relaxed): array
{
    $old = [getenv('APP_ENV'), getenv('AUTH_OTP_E2E_RELAXED')];
    putenv("APP_ENV={$env}");
    putenv("AUTH_OTP_E2E_RELAXED={$relaxed}");
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
    $_ENV['AUTH_OTP_E2E_RELAXED'] = $_SERVER['AUTH_OTP_E2E_RELAXED'] = $relaxed;

    try {
        return (require dirname(__DIR__, 2).'/config/auth.php')['otp'];
    } finally {
        putenv('APP_ENV='.$old[0]);
        $old[1] === false ? putenv('AUTH_OTP_E2E_RELAXED') : putenv('AUTH_OTP_E2E_RELAXED='.$old[1]);
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $old[0];
        unset($_ENV['AUTH_OTP_E2E_RELAXED'], $_SERVER['AUTH_OTP_E2E_RELAXED']);
    }
}

test('production bo qua AUTH_OTP_E2E_RELAXED, giu 1/phut 5/gio 10/ngay', function () {
    $otp = vvLoadAuthConfig('production', 'true');

    expect($otp['e2e_relaxed'])->toBeFalse()
        ->and($otp['cooldown_seconds'])->toBe(60)
        ->and($otp['max_per_hour'])->toBe(5)
        ->and($otp['max_per_day'])->toBe(10)
        ->and($otp['send_per_minute'])->toBe(1);
});

test('local bat AUTH_OTP_E2E_RELAXED thi nới hạn mức', function () {
    $otp = vvLoadAuthConfig('local', 'true');

    expect($otp['e2e_relaxed'])->toBeTrue()->and($otp['max_per_hour'])->toBeGreaterThan(100)->and($otp['send_per_minute'])->toBeGreaterThan(10);
});
