<?php

use App\Support\ProductionConfigGuard;
use Dotenv\Dotenv;

/**
 * T38.2 — `ProductionConfigGuard::guardManualPayment()` (ADR-007 §11) + `.env.example` cho US-022.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'session.secure' => true, 'session.encrypt' => true,
        'cache.limiter' => 'redis-limiter', 'captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app',
        'auth.otp.channels' => ['email'],
        'auth.otp.e2e_relaxed' => false,
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net',
        'app.url' => 'https://api.vitaminvui.vn',
        'app.frontend_url' => 'https://vitaminvui.vn',
        'app.admin_url' => 'https://admin.vitaminvui.vn',
        'app.trusted_proxies' => '10.0.0.1,10.0.0.2',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
        'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn'],
        'video.provider' => 'internal',
        'video.enabled_providers' => ['internal'],
        'internal.required' => true,
        'internal.ssr_token' => str_repeat('a', 64),
        'internal.ssr_token_min_length' => 32,
        'features.paid_checkout' => false,
        'videolab.enabled' => true,
        'videolab.api_key' => str_repeat('a', 32),
        'videolab.token_key' => str_repeat('b', 32),
        'videolab.webhook_secret' => str_repeat('c', 32),
        // US-022: cấu hình hợp lệ khi bật thủ công
        'features.manual_payment' => true,
        'orders.manual.pending_ttl_hours' => 72,
        'orders.manual.approval_window_days' => 30,
        'orders.manual.per_day' => 5,
        'orders.manual.notify_emails' => ['ops@vitaminvui.vn'],
        'orders.manual.contact' => ['phone' => '0901234567', 'zalo_url' => null, 'email' => null, 'hours' => null],
    ]);
});

function vvMoGuard(): void
{
    (new ProductionConfigGuard)->check();
}

test('cờ manual tắt: guard không kiểm gì thêm (kể cả cấu hình rác)', function () {
    config(['features.manual_payment' => false, 'orders.manual.notify_emails' => [], 'orders.manual.contact' => [], 'orders.manual.per_day' => 999]);

    expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class);
});

test('cờ manual bật + cấu hình hợp lệ qua guard (production và staging); guard MoMo/ipn_ready không đổi', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class);

    config(['features.paid_checkout' => true]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'ipn_ready');
})->with(['production', 'staging']);

test('phải có ít nhất 1 kênh liên hệ (SĐT, Zalo hoặc email)', function () {
    config(['orders.manual.contact' => ['phone' => null, 'zalo_url' => null, 'email' => null, 'hours' => '8-21']]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'kênh liên hệ');

    config(['orders.manual.contact' => ['phone' => '  ', 'zalo_url' => '', 'email' => '', 'hours' => null]]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'kênh liên hệ');

    foreach ([['phone' => '0901234567'], ['zalo_url' => 'https://zalo.me/0901234567'], ['email' => 'hotro@vitaminvui.vn']] as $one) {
        config(['orders.manual.contact' => $one + ['phone' => null, 'zalo_url' => null, 'email' => null, 'hours' => null]]);
        expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class);
    }
});

test('Zalo URL phải dạng https://zalo.me/<id>', function (string $url, bool $ok) {
    config(['orders.manual.contact' => ['phone' => '0901234567', 'zalo_url' => $url, 'email' => null, 'hours' => null]]);

    $ok ? expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class) : expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'ZALO');
})->with([
    ['https://zalo.me/0901234567', true],
    ['https://zalo.me/abc.def-1_2', true],
    ['http://zalo.me/0901234567', false],
    ['https://evil.com/https://zalo.me/1', false],
    ['https://zalo.me.evil.com/1', false],
    ['https://zalo.me/', false],
    ['https://zalo.me/a/b', false],
    ['zalo.me/1', false],
]);

test('email kênh liên hệ và hours được kiểm', function () {
    config(['orders.manual.contact' => ['phone' => '0901234567', 'zalo_url' => null, 'email' => 'khong-hop-le', 'hours' => null]]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'PAYMENT_CONTACT_EMAIL');

    config(['orders.manual.contact' => ['phone' => '0901234567', 'zalo_url' => null, 'email' => null, 'hours' => str_repeat('a', 101)]]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'PAYMENT_CONTACT_HOURS');
});

test('ORDERS_MANUAL_NOTIFY_EMAILS: không rỗng, mọi địa chỉ hợp lệ', function () {
    config(['orders.manual.notify_emails' => []]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'ORDERS_MANUAL_NOTIFY_EMAILS');

    config(['orders.manual.notify_emails' => ['ops@vitaminvui.vn', 'sai-dia-chi']]);
    expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'ORDERS_MANUAL_NOTIFY_EMAILS');

    config(['orders.manual.notify_emails' => ['a@vitaminvui.vn', 'b@vitaminvui.vn']]);
    expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class);
});

test('TTL 1..168, cửa sổ duyệt muộn 0..90, hạn mức 1..50: biên trong qua, biên ngoài bị chặn', function (string $key, int $value, bool $ok) {
    config([$key => $value]);

    $ok ? expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class) : expect(fn () => vvMoGuard())->toThrow(RuntimeException::class);
})->with([
    ['orders.manual.pending_ttl_hours', 1, true], ['orders.manual.pending_ttl_hours', 168, true],
    ['orders.manual.pending_ttl_hours', 0, false], ['orders.manual.pending_ttl_hours', 169, false],
    ['orders.manual.approval_window_days', 0, true], ['orders.manual.approval_window_days', 90, true],
    ['orders.manual.approval_window_days', -1, false], ['orders.manual.approval_window_days', 91, false],
    ['orders.manual.per_day', 1, true], ['orders.manual.per_day', 50, true],
    ['orders.manual.per_day', 0, false], ['orders.manual.per_day', 51, false],
]);

test('biến env mới không được dính chú thích cuối dòng (ENV_KEYS_NO_INLINE_COMMENT)', function (string $key) {
    $_ENV[$key] = $_SERVER[$key] = 'true # ghi chu';
    try {
        expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, $key);
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
    }
})->with(['FEATURE_MANUAL_PAYMENT', 'ORDERS_MANUAL_PENDING_TTL_HOURS', 'ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 'ORDERS_MANUAL_PER_DAY', 'ORDERS_MANUAL_NOTIFY_EMAILS', 'PAYMENT_CONTACT_PHONE', 'PAYMENT_CONTACT_ZALO_URL', 'PAYMENT_CONTACT_EMAIL', 'PAYMENT_CONTACT_HOURS']);

test('.env.example (local) bật manual, .env.production.example tắt; cùng đủ các biến US-022', function () {
    $keys = ['FEATURE_MANUAL_PAYMENT', 'ORDERS_MANUAL_PENDING_TTL_HOURS', 'ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 'ORDERS_MANUAL_PER_DAY', 'ORDERS_MANUAL_NOTIFY_EMAILS', 'PAYMENT_CONTACT_PHONE', 'PAYMENT_CONTACT_ZALO_URL', 'PAYMENT_CONTACT_EMAIL', 'PAYMENT_CONTACT_HOURS'];

    $local = Dotenv::parse((string) file_get_contents(base_path('.env.example')));
    expect($local['FEATURE_MANUAL_PAYMENT'])->toBe('true');
    foreach ($keys as $k) {
        expect(array_key_exists($k, $local))->toBeTrue("{$k} thiếu ở .env.example");
    }

    $prodPath = base_path('../infra/production/.env.production.example');
    if (! is_file($prodPath)) {
        $this->markTestSkipped('infra/ không được mount.');
    }
    $prod = Dotenv::parse((string) file_get_contents($prodPath));
    expect($prod['FEATURE_MANUAL_PAYMENT'])->toBe('false');
    foreach ($keys as $k) {
        expect(array_key_exists($k, $prod))->toBeTrue("{$k} thiếu ở .env.production.example");
    }
});

test('S4: PAYMENT_CONTACT_PHONE phải là số điện thoại; PAYMENT_CONTACT_HOURS không HTML/ký tự điều khiển', function (string $phone, bool $ok) {
    config(['orders.manual.contact' => ['phone' => $phone, 'zalo_url' => null, 'email' => null, 'hours' => null]]);

    $ok ? expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class) : expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'PAYMENT_CONTACT_PHONE');
})->with([
    ['0901 234 567', true], ['+84 901.234.567', true], ['028-3822-1234', true],
    ['abc12345678', false], ['javascript:alert(1)', false], ['12345', false], ['0901<b>234567', false], ['--------', false],
]);

test('S4: hours chặn <, >, ký tự điều khiển, bidi; chữ tiếng Việt và dấu gạch qua', function (string $hours, bool $ok) {
    config(['orders.manual.contact' => ['phone' => '0901234567', 'zalo_url' => null, 'email' => null, 'hours' => $hours]]);

    $ok ? expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class) : expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, 'PAYMENT_CONTACT_HOURS');
})->with([
    ['8:00–21:00 hằng ngày (trừ lễ)', true], ['<b>8h</b>', false], ["8h\n21h", false], ["8h\u{202E}21h", false],
]);

test('R5: giá trị env thô của TTL/window/per_day phải là số nguyên (abc -> chặn dù (int) = 0)', function (string $key, string $raw, bool $ok) {
    $_ENV[$key] = $_SERVER[$key] = $raw;
    try {
        $ok ? expect(fn () => vvMoGuard())->not->toThrow(RuntimeException::class) : expect(fn () => vvMoGuard())->toThrow(RuntimeException::class, $key);
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
    }
})->with([
    ['ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 'abc', false], ['ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', '30', true],
    ['ORDERS_MANUAL_PER_DAY', '5x', false], ['ORDERS_MANUAL_PENDING_TTL_HOURS', '-1', false], ['ORDERS_MANUAL_PENDING_TTL_HOURS', '72', true],
]);

test('R3: ORDERS_MANUAL_NOTIFY_EMAILS rỗng hoặc không đặt -> dùng SUPPORT_EMAIL; có giá trị -> dùng giá trị (danh sách, trim)', function () {
    $load = function (array $env): array {
        $backup = [];
        foreach (['ORDERS_MANUAL_NOTIFY_EMAILS', 'SUPPORT_EMAIL'] as $k) {
            $backup[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null];
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        foreach ($env as $k => $v) {
            $_ENV[$k] = $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }
        try {
            return require config_path('orders.php');
        } finally {
            foreach ($backup as $k => [$e, $s]) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
                if ($e !== null) {
                    $_ENV[$k] = $e;
                    putenv("{$k}={$e}");
                }
                if ($s !== null) {
                    $_SERVER[$k] = $s;
                }
            }
        }
    };

    expect($load(['SUPPORT_EMAIL' => 'sup@x.vn'])['manual']['notify_emails'])->toBe(['sup@x.vn']);
    expect($load(['ORDERS_MANUAL_NOTIFY_EMAILS' => '', 'SUPPORT_EMAIL' => 'sup@x.vn'])['manual']['notify_emails'])->toBe(['sup@x.vn']);
    expect($load(['ORDERS_MANUAL_NOTIFY_EMAILS' => ' a@x.vn , b@x.vn ', 'SUPPORT_EMAIL' => 'sup@x.vn'])['manual']['notify_emails'])->toBe(['a@x.vn', 'b@x.vn']);
});
