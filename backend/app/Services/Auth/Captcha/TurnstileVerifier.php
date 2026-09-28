<?php

namespace App\Services\Auth\Captcha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            // L5 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY lỗi
            // mạng/timeout không được bắt, nên người dùng nhận 500
            // `INTERNAL_ERROR` (và ghi log mức "error" kèm stack trace) thay
            // vì 422 `CAPTCHA_FAILED` như mọi captcha sai khác. Vẫn fail-closed
            // (return false) — chỉ đổi CÁCH báo lỗi, không nới an toàn.
            Log::warning('Turnstile: không gọi được API xác minh (fail-closed).', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed() || ! (bool) $response->json('success', false)) {
            return false;
        }

        // L5 — kiểm `hostname` khi Cloudflare có trả về (không thêm biến môi
        // trường mới: dùng lại `app.frontend_url` đã có sẵn). Token lấy từ
        // widget hiển thị ở host khác (cùng site key) thì không dùng lại được
        // ở đây. Bỏ qua kiểm tra (không chặn) nếu response không có `hostname`
        // hoặc `FRONTEND_URL` không parse được host — tránh chặn nhầm vì thay
        // đổi không mong đợi từ phía Cloudflare.
        $hostname = $response->json('hostname');

        if (is_string($hostname) && $hostname !== '') {
            $expectedHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

            if (is_string($expectedHost) && $expectedHost !== '' && $hostname !== $expectedHost) {
                return false;
            }
        }

        return true;
    }
}
