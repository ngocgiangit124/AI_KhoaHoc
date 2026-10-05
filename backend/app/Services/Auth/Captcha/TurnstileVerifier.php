<?php

namespace App\Services\Auth\Captcha;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile siteverify. Fail-closed: lỗi mạng/timeout/phản hồi lạ → false.
 */
class TurnstileVerifier implements CaptchaVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = (string) config('services.turnstile.secret');

        if ($token === null || $token === '' || strlen($token) > 2048 || $secret === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::ENDPOINT, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }
}
