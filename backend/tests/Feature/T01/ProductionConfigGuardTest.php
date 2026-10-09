<?php

use App\Support\ProductionConfigGuard;

/**
 * M4 (review bảo mật T01/T02) — mỗi cấu hình sai phải làm app "không boot"
 * (ném RuntimeException) khi ở production. Gọi trực tiếp `check()` sau khi ép
 * environment/config vì không thể khởi động lại cả ứng dụng trong 1 tiến trình
 * test — đúng tinh thần bài test, không phải khởi động lại `php-fpm` thật.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    // Baseline hợp lệ — mỗi test chỉ phá đúng 1 điều kiện.
    config([
        'app.debug' => false,
        'session.secure' => true, 'session.encrypt' => true,
        'cache.limiter' => 'redis-limiter',
        'captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net',
        'app.trusted_proxies' => '10.0.0.1,10.0.0.2',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
    ]);
});

test('cau hinh hop le thi khong nem loi', function () {
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('khong o production thi bo qua moi kiem tra', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['app.debug' => true, 'session.secure' => false]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('APP_DEBUG=true o production nem loi', function () {
    config(['app.debug' => true]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('session.secure tat o production nem loi', function () {
    config(['session.secure' => false]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('CAPTCHA_DRIVER=fake o production nem loi (khong phan biet hoa thuong)', function () {
    config(['captcha.driver' => 'FAKE']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('GL-1 D6: TURNSTILE_SECRET rong khi captcha turnstile nem loi', function (?string $secret) {
    config(['services.turnstile.secret' => $secret, 'app.trusted_proxies_console_exempt' => false]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'TURNSTILE_SECRET');
})->with([[null], [''], ['   ']]);

test('GL-1 D6: MAIL_MAILER log/array/rong o production nem loi', function (string $mailer) {
    config(['mail.default' => $mailer]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'MAIL_MAILER');
})->with(['log', 'array', 'LOG', '']);

test('GL-1 D6: mailer that (smtp/ses) khong bi chan', function (string $mailer) {
    config(['mail.default' => $mailer]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
})->with(['smtp', 'ses']);

test('GL-1 A5: khoa test Turnstile (secret hoac site key) nem loi', function (string $key) {
    config(['services.turnstile.secret' => $key]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'khoá test');

    config(['services.turnstile.secret' => 'real-secret', 'services.turnstile.site_key' => $key]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'khoá test');
})->with(['1x0000000000000000000000000000000AA', '2x00000000000000000000AB', '3x0000000000000000000000000000000AA']);

test('GL-1 A5: khoa Turnstile that khong bi chan', function () {
    config(['services.turnstile.secret' => '0x4AAAAAAAreal', 'services.turnstile.site_key' => '0x4AAAAAAAsite']);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-1 A5: REDIS_PASSWORD rong nem loi', function (?string $password) {
    config(['database.redis.default.password' => $password, 'app.trusted_proxies_console_exempt' => false]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'REDIS_PASSWORD');
})->with([[null], [''], ['  ']]);

test('GL-1 A5: mat khau Redis nam trong url duoc chap nhan', function () {
    config(['database.redis.default.password' => null, 'database.redis.default.url' => 'redis://:s3cret@redis:6379', 'app.trusted_proxies_console_exempt' => false]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-1 A5: worker-video chi co REDIS_VIDEO_* (khong co REDIS_PASSWORD chinh) khong bi chan', function () {
    config(['database.redis.default.password' => null, 'database.redis.video.password' => 'video-secret', 'app.trusted_proxies_console_exempt' => true]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-1 A5: worker-video thieu REDIS_VIDEO_PASSWORD bi chan; web thieu mat khau video cung bi chan', function () {
    config(['database.redis.default.password' => null, 'database.redis.video.password' => '', 'app.trusted_proxies_console_exempt' => true]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'REDIS_VIDEO_PASSWORD');

    config(['database.redis.default.password' => 'x', 'database.redis.video.password' => '', 'app.trusted_proxies_console_exempt' => false]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'REDIS_VIDEO_PASSWORD');
});

test('GL-1 A5: DB_USERNAME=root nem loi (khong phan biet hoa thuong)', function (string $user) {
    config(['database.connections.'.config('database.default').'.username' => $user]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'DB_USERNAME');
})->with(['root', 'ROOT']);

test('GL-1 A5: staging cung bi chan, local/testing khong', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['database.redis.default.password' => null, 'app.trusted_proxies_console_exempt' => false]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'REDIS_PASSWORD');

    app()->detectEnvironment(fn () => 'local');
    config(['database.redis.default.password' => null, 'mail.default' => 'log', 'services.turnstile.secret' => '1x0000000000000000000000000000000AA']);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-1: console duoc mien TURNSTILE_SECRET rong (worker-video) nhung van chan khoa test', function () {
    config(['services.turnstile.secret' => null, 'app.trusted_proxies_console_exempt' => true]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);

    config(['services.turnstile.secret' => '1x0000000000000000000000000000000AA']);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'khoá test');
});

test('SANCTUM_STATEFUL_DOMAINS chua localhost o production nem loi', function () {
    config(['sanctum.stateful' => ['vitaminvui.vn', 'localhost:3000']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('SANCTUM_STATEFUL_DOMAINS chua 127.0.0.1 o production nem loi', function () {
    config(['sanctum.stateful' => ['127.0.0.1:8000']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('TRUSTED_PROXIES la wildcard o production nem loi', function () {
    config(['app.trusted_proxies' => '*']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('gateway fake viet hoa o production van bi chan (khong phan biet hoa thuong)', function () {
    config(['payments.enabled_gateways' => ['Fake']]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('MOMO_ENDPOINT ngoai allowlist o production nem loi', function () {
    config(['payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn/v2/gateway/api/create']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('MOMO_ENDPOINT sai scheme (http) o production nem loi', function () {
    config(['payments.gateways.momo.endpoint' => 'http://payment.momo.vn/v2/gateway/api/create']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('GL-A2 S6: ngưỡng/trần đăng nhập hợp lệ (mặc định) không ném lỗi', function () {
    config([
        'auth.login.captcha_threshold' => 5, 'auth.login.max_failures_per_account' => 100, 'auth.login.max_failures_per_ip' => 200,
        'auth.staff.login_captcha_threshold' => 5, 'auth.staff.login_max_failures_per_account' => 100, 'auth.staff.login_max_failures_per_ip' => 200,
    ]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-A2 S6: ngưỡng captcha ngoài 1..20, trần tài khoản < 20 hoặc <= ngưỡng+10, trần IP < 20 (env rỗng = 0) ném lỗi, cả học sinh và staff', function (string $key, mixed $value, string $message) {
    config([$key => $value]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, $message);
})->with([
    ['auth.login.captcha_threshold', 0, 'AUTH_LOGIN_CAPTCHA_THRESHOLD'],
    ['auth.login.captcha_threshold', 21, 'AUTH_LOGIN_CAPTCHA_THRESHOLD'],
    ['auth.login.max_failures_per_account', 0, 'AUTH_LOGIN_MAX_FAILURES'],
    ['auth.login.max_failures_per_account', 19, 'AUTH_LOGIN_MAX_FAILURES'],
    ['auth.login.max_failures_per_account', 15, 'AUTH_LOGIN_MAX_FAILURES'],
    ['auth.login.max_failures_per_ip', 0, 'AUTH_LOGIN_MAX_FAILURES_IP'],
    ['auth.login.max_failures_per_ip', 19, 'AUTH_LOGIN_MAX_FAILURES_IP'],
    ['auth.staff.login_captcha_threshold', 0, 'AUTH_STAFF_LOGIN_CAPTCHA_THRESHOLD'],
    ['auth.staff.login_captcha_threshold', 21, 'AUTH_STAFF_LOGIN_CAPTCHA_THRESHOLD'],
    ['auth.staff.login_max_failures_per_account', 0, 'AUTH_STAFF_LOGIN_MAX_FAILURES'],
    ['auth.staff.login_max_failures_per_account', 15, 'AUTH_STAFF_LOGIN_MAX_FAILURES'],
    ['auth.staff.login_max_failures_per_ip', 0, 'AUTH_STAFF_LOGIN_MAX_FAILURES_IP'],
]);

test('GL-A2 S6: local/testing không bị kiểm ngưỡng đăng nhập', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login.captcha_threshold' => 0, 'auth.login.max_failures_per_account' => 0]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('GL-A2 V2-2/V2-4: trần IP có captcha < 100 và limiter captcha-reject < 10 (env rỗng = 0) ném lỗi', function (string $key, mixed $value, string $message) {
    config([$key => $value]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, $message);
})->with([
    ['auth.login.max_captcha_failures_per_ip', 99, 'AUTH_LOGIN_MAX_CAPTCHA_FAILURES_IP'],
    ['auth.login.max_captcha_failures_per_ip', 0, 'AUTH_LOGIN_MAX_CAPTCHA_FAILURES_IP'],
    ['auth.staff.login_max_captcha_failures_per_ip', 99, 'AUTH_STAFF_LOGIN_MAX_CAPTCHA_FAILURES_IP'],
    ['auth.login.captcha_rejects_per_minute', 0, 'AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE'],
    ['auth.login.captcha_rejects_per_minute', 9, 'AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE'],
    ['auth.login.captcha_rejects_per_minute_ip', 9, 'AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE_IP'],
]);

test('GL-A2 V2-5: CACHE_LIMITER phải là store driver redis ở production/staging', function (string $store) {
    config(['cache.limiter' => $store]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'CACHE_LIMITER');
})->with(['array', 'file', 'database', 'khong-ton-tai']);

test('GL-A2 V2-5: local/testing dùng limiter array vẫn được', function () {
    app()->detectEnvironment(fn () => 'testing');
    config(['cache.limiter' => 'array']);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});
