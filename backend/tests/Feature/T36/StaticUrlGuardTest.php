<?php

use App\Support\ProductionConfigGuard;

/**
 * (j) ProductionConfigGuard::guardStaticUrl (US-020): ở production/staging, STATIC_URL phải đặt, dùng https và KHÁC host
 * (kể cả quan hệ cha/con) của APP_URL, FRONTEND_URL, ADMIN_URL; không nằm dưới SESSION_DOMAIN; consent_version khác rỗng.
 */
beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'session.secure' => true, 'session.encrypt' => true, 'session.domain' => null,
        'captcha.driver' => 'turnstile',
        'auth.otp.channels' => ['email'],
        'auth.otp.e2e_relaxed' => false,
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'],
        'app.url' => 'https://api.vitaminvui.vn',
        'app.frontend_url' => 'https://vitaminvui.vn',
        'app.admin_url' => 'https://admin.vitaminvui.vn',
        'app.static_url' => 'https://static.vitaminvui-media.net',
        'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
        'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn'],
        'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
        'internal.required' => false, 'internal.ssr_token' => null,
        'features.paid_checkout' => false, 'features.staff_mfa' => true,
        'videolab.enabled' => false,
        'teacher_profile.consent_version' => '2026-10',
    ]);
});

function vvStaticGuard(): void
{
    (new ProductionConfigGuard)->check();
}

test('STATIC_URL hop le (https, mien rieng) qua o production va staging', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);
})->with(['production', 'staging']);

test('STATIC_URL: rong/null, http, khong phai URL hop le bi chan o production va staging', function (mixed $value, string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['app.static_url' => $value]);

    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'STATIC_URL');
})->with([
    'rong' => [''],
    'null' => [null],
    'toan khoang trang' => ['   '],
    'http' => ['http://static.vitaminvui-media.net'],
    'HTTP viet hoa' => ['HTTP://static.vitaminvui-media.net'],
    'khong scheme' => ['static.vitaminvui-media.net'],
    'ftp' => ['ftp://static.vitaminvui-media.net'],
    'https khong host' => ['https://'],
])->with(['production', 'staging']);

test('STATIC_URL: trung host voi APP_URL, FRONTEND_URL hoac ADMIN_URL bi chan (khong phan biet hoa thuong, ke ca cong khac nhau)', function (string $static) {
    config(['app.static_url' => $static]);

    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'STATIC_URL');
})->with([
    'trung api' => ['https://api.vitaminvui.vn/uploads'],
    'trung web' => ['https://vitaminvui.vn'],
    'trung admin' => ['https://admin.vitaminvui.vn'],
    'viet hoa' => ['https://ADMIN.VitaminVui.vn'],
    'khac cong' => ['https://vitaminvui.vn:8443'],
]);

test('STATIC_URL: mien con cua mien web/admin va mien cha cua api bi chan (cookie Domain= cua mien cha phu ca mien con)', function (string $static) {
    config(['app.static_url' => $static]);

    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'STATIC_URL');
})->with([
    'static duoi web' => ['https://static.vitaminvui.vn'],
    'static duoi admin' => ['https://cdn.admin.vitaminvui.vn'],
    'static la cha cua api' => ['https://vitaminvui.vn'],
]);

test('STATIC_URL: mien khac hoan toan va mien trung ten mien lai (vitaminvui.vn.evil.com) khong bi chan nham', function () {
    config(['app.static_url' => 'https://static.vitaminvui.vn.evil.example']);
    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);

    config(['app.static_url' => 'https://xvitaminvui.vn']);
    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);
});

test('STATIC_URL: SESSION_DOMAIN phu ca host STATIC_URL thi bi chan; host-only (null) thi qua', function () {
    config(['session.domain' => '.vitaminvui-media.net']);
    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'SESSION_DOMAIN');

    config(['session.domain' => 'static.vitaminvui-media.net']);
    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'SESSION_DOMAIN');

    config(['session.domain' => '.vitaminvui.vn']);
    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);

    foreach ([null, '', 'null'] as $hostOnly) {
        config(['session.domain' => $hostOnly]);
        expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);
    }
});

test('teacher_profile.consent_version rong bi chan o production/staging', function (mixed $value) {
    config(['teacher_profile.consent_version' => $value]);

    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'consent_version');
})->with(['', null, '   ']);

test('local va testing khong bi guardStaticUrl chan du cau hinh sai', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['app.static_url' => 'http://localhost:8080', 'teacher_profile.consent_version' => '', 'app.debug' => true]);

    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);
})->with(['local', 'testing']);

// ---------------------------------------------------------------- L3 (security T36): registrable domain, api/admin-api, chuẩn hoá, IP

test('L3: FRONTEND www + static cung site (anh em) bi chan; api/admin-api cung site bi chan', function (array $config) {
    config($config);

    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'STATIC_URL');
})->with([
    'www + static.vitaminvui.vn' => [['app.frontend_url' => 'https://www.vitaminvui.vn', 'app.static_url' => 'https://static.vitaminvui.vn']],
    'www + admin-api trung host admin-api' => [['app.frontend_url' => 'https://www.vitaminvui.vn', 'app.admin_api_host' => 'admin-api.vitaminvui.vn', 'app.api_host' => 'api.vitaminvui.vn', 'app.static_url' => 'https://admin-api.vitaminvui.vn']],
    'static anh em cua api host' => [['app.api_host' => 'api.vitaminvui.vn', 'app.static_url' => 'https://cdn.vitaminvui.vn', 'app.url' => 'https://other.example']],
    'static cung site voi admin-api host' => [['app.admin_api_host' => 'admin-api.vitaminvui.vn', 'app.static_url' => 'https://img.vitaminvui.vn', 'app.url' => 'https://other.example', 'app.frontend_url' => 'https://other.example', 'app.admin_url' => 'https://other.example']],
    'dau cham cuoi (host web)' => [['app.static_url' => 'https://vitaminvui.vn.']],
    'dau cham cuoi (host api)' => [['app.static_url' => 'https://api.vitaminvui.vn.']],
    'dau cham cuoi + mien con' => [['app.static_url' => 'https://static.vitaminvui.vn.']],
    'dau cham toan chieu rong U+FF0E' => [['app.static_url' => "https://static\u{FF0E}vitaminvui.vn"]],
    'dau cham CJK U+3002' => [['app.static_url' => "https://static\u{3002}vitaminvui.vn"]],
    'viet hoa + dau cham cuoi' => [['app.static_url' => 'https://STATIC.VitaminVui.VN.']],
    '.com.vn cung site' => [['app.url' => 'https://api.vitaminvui.com.vn', 'app.frontend_url' => 'https://www.vitaminvui.com.vn', 'app.admin_url' => 'https://admin.vitaminvui.com.vn', 'app.static_url' => 'https://static.vitaminvui.com.vn', 'sanctum.stateful' => ['www.vitaminvui.com.vn', 'admin.vitaminvui.com.vn']]],
    'IPv4' => [['app.static_url' => 'https://127.0.0.1']],
    'IPv4 khac' => [['app.static_url' => 'https://203.0.113.10:8443/uploads']],
    'IPv6' => [['app.static_url' => 'https://[2001:db8::1]']],
]);

test('L3: mien tinh rieng hop le van qua (khong chan nham): khac registrable domain, .com.vn anh em khac ten, www + mien media', function (array $config) {
    config($config);

    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);
})->with([
    'media rieng' => [['app.frontend_url' => 'https://www.vitaminvui.vn', 'sanctum.stateful' => ['www.vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net']],
    'dau cham cuoi mien rieng' => [['app.static_url' => 'https://static.vitaminvui-media.net.']],
    'mien rieng viet hoa' => [['app.static_url' => 'https://STATIC.VitaminVui-Media.NET']],
    '.com.vn khac ten' => [['app.url' => 'https://api.vitaminvui.com.vn', 'app.frontend_url' => 'https://www.vitaminvui.com.vn', 'app.admin_url' => 'https://admin.vitaminvui.com.vn', 'app.static_url' => 'https://static.khac.com.vn', 'sanctum.stateful' => ['www.vitaminvui.com.vn', 'admin.vitaminvui.com.vn']]],
    'cung tien to nhung khac mien' => [['app.static_url' => 'https://vitaminvui.vn.evil.example']],
    'khac duoi' => [['app.static_url' => 'https://vitaminvui.com']],
]);

test('L3: STATIC_URL voi cong va duong dan van duoc nhan dien host dung', function () {
    config(['app.static_url' => 'https://static.vitaminvui-media.net:8443/uploads/']);
    expect(fn () => vvStaticGuard())->not->toThrow(RuntimeException::class);

    config(['app.static_url' => 'https://api.vitaminvui.vn:8443/uploads/']);
    expect(fn () => vvStaticGuard())->toThrow(RuntimeException::class, 'STATIC_URL');
});
