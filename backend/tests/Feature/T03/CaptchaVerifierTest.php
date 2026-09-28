<?php

use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\Captcha\TurnstileVerifier;
use Illuminate\Support\Facades\Http;

test('captcha.driver=fake (mac dinh testing) bind FakeCaptchaVerifier', function () {
    config(['captcha.driver' => 'fake']);

    expect(app(CaptchaVerifier::class))->toBeInstanceOf(FakeCaptchaVerifier::class);
});

test('captcha.driver=turnstile bind TurnstileVerifier', function () {
    config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'secret-test']);

    expect(app(CaptchaVerifier::class))->toBeInstanceOf(TurnstileVerifier::class);
});

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
