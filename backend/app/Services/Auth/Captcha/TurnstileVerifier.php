<?php

namespace App\Services\Auth\Captcha;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile thật — `CAPTCHA_DRIVER=turnstile` (staging/production).
 * `services.turnstile.secret` rỗng → luôn coi là sai (fail-closed).
 */
class TurnstileVerifier implements CaptchaVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly string $secret) {}

    public function verify(string $token, ?string $ip = null): bool
    {
        if ($this->secret === '' || $token === '') {
            return false;
        }

        $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
            'secret' => $this->secret,
            'response' => $token,
            'remoteip' => $ip,
        ]));

        if ($response->failed()) {
            return false;
        }

        return (bool) $response->json('success', false);
    }
}
