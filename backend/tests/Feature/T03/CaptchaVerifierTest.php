<?php

use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\Captcha\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('captcha.driver=fake (mac dinh testing) bind FakeCaptchaVerifier', function () {
    config(['captcha.driver' => 'fake']);

    expect(app(CaptchaVerifier::class))->toBeInstanceOf(FakeCaptchaVerifier::class);
});

test('captcha.driver=turnstile bind TurnstileVerifier', function () {
    config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'secret-test']);

    expect(app(CaptchaVerifier::class))->toBeInstanceOf(TurnstileVerifier::class);
});

/**
 * M3 (review docs/security/review-T03-FW1.md) — binding KHÔNG còn coi driver
 * lạ là "fake" (`default => new FakeCaptchaVerifier`) — phải ném exception để
 * lỗi cấu hình bị phát hiện ngay lúc resolve, không âm thầm chạy không có
 * captcha thật.
 */
test('captcha.driver la gia tri la nem RuntimeException khi resolve (M3)', function (string $driver) {
    config(['captcha.driver' => $driver]);

    expect(fn () => app(CaptchaVerifier::class))->toThrow(RuntimeException::class);
})->with(['Turnstile', 'TURNSTILE', 'fake ', '', 'none']);

test('FakeCaptchaVerifier chi coi la sai voi sentinel INVALID_TOKEN', function () {
    $verifier = new FakeCaptchaVerifier;

    expect($verifier->verify('bat-ky-token-nao'))->toBeTrue();
    expect($verifier->verify(''))->toBeFalse();
    expect($verifier->verify(FakeCaptchaVerifier::INVALID_TOKEN))->toBeFalse();
});

test('TurnstileVerifier goi dung endpoint va tra false khi Cloudflare tra success=false', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
    ]);

    $verifier = new TurnstileVerifier('secret-that');

    expect($verifier->verify('token-tu-frontend', '203.0.113.5'))->toBeFalse();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'secret-that'
            && $request['response'] === 'token-tu-frontend';
    });
});

test('TurnstileVerifier tra true khi Cloudflare xac nhan thanh cong', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    $verifier = new TurnstileVerifier('secret-that');

    expect($verifier->verify('token-hop-le'))->toBeTrue();
});

test('TurnstileVerifier fail-closed khi secret rong', function () {
    $verifier = new TurnstileVerifier('');

    expect($verifier->verify('bat-ky'))->toBeFalse();
});

/**
 * L5 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY lỗi kết nối/timeout
 * không được bắt: người dùng nhận 500 `INTERNAL_ERROR` thay vì 422
 * `CAPTCHA_FAILED` như mọi captcha sai khác (dù vẫn fail-closed vì
 * `RegistrationService` không tạo tài khoản khi captcha "đúng" không được xác
 * nhận). Giờ lỗi kết nối phải được bắt và coi là verify() = false.
 */
test('TurnstileVerifier fail-closed khi loi ket noi/timeout, khong nem exception (L5)', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $verifier = new TurnstileVerifier('secret-that');

    expect($verifier->verify('token-bat-ky'))->toBeFalse();
});

/**
 * L5 — kiểm `hostname` (dùng lại `app.frontend_url` có sẵn, không thêm biến
 * môi trường mới): token xác minh ở host KHÁC (cùng site key) không được coi
 * là hợp lệ ở đây.
 */
test('TurnstileVerifier tra false khi hostname trong response khac voi FRONTEND_URL (L5)', function () {
    config(['app.frontend_url' => 'http://localhost:3000']);

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'hostname' => 'evil.example.com',
        ], 200),
    ]);

    $verifier = new TurnstileVerifier('secret-that');

    expect($verifier->verify('token-tu-host-khac'))->toBeFalse();
});

test('TurnstileVerifier tra true khi hostname khop voi FRONTEND_URL (L5)', function () {
    config(['app.frontend_url' => 'http://localhost:3000']);

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'hostname' => 'localhost',
        ], 200),
    ]);

    $verifier = new TurnstileVerifier('secret-that');

    expect($verifier->verify('token-hop-le'))->toBeTrue();
});
