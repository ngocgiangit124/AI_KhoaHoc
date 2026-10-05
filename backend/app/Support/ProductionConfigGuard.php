<?php

namespace App\Support;

use RuntimeException;

/**
 * M4 (review bảo mật T01/T02) — chặn ứng dụng khởi động ở production nếu bất
 * kỳ cấu hình nào dưới đây sai, theo kiểu ALLOWLIST (kiểm đúng giá trị mong
 * đợi) thay vì blocklist (chỉ so khớp 1 chuỗi cụ thể, dễ lọt biến thể viết hoa
 * hay giá trị khác chưa nghĩ tới) — S4, S22.
 *
 * Tách thành class riêng (không để thẳng trong AppServiceProvider::boot()) để
 * test được từng điều kiện độc lập bằng cách gọi `check()` trực tiếp sau khi
 * đổi `app()->detectEnvironment()`/`config()`, không cần khởi động lại cả ứng
 * dụng (không khả thi trong 1 tiến trình test).
 */
class ProductionConfigGuard
{
    public function check(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        throw_if(
            (bool) config('app.debug'),
            RuntimeException::class,
            'APP_DEBUG phải là false ở production (M4).'
        );

        throw_if(
            ! config('session.secure'),
            RuntimeException::class,
            'SESSION_SECURE_COOKIE phải bật (true) ở production (M4).'
        );

        $this->guardCaptcha();
        $this->guardOtpChannels();
        $this->guardStatefulDomains();
        $this->guardTrustedProxies();
        $this->guardPayments();
    }

    private function guardCaptcha(): void
    {
        $driver = mb_strtolower((string) config('captcha.driver'));

        throw_if(
            $driver === 'fake',
            RuntimeException::class,
            'CAPTCHA_DRIVER=fake bị cấm ở production (M4).'
        );
    }

    /**
     * S9 — production chưa có nhà cung cấp SMS thật: cấm bật kênh `sms` (mã sẽ không tới người dùng).
     */
    private function guardOtpChannels(): void
    {
        $channels = array_map(
            static fn ($channel) => mb_strtolower((string) $channel),
            (array) config('auth.otp.channels', [])
        );

        throw_if(
            in_array('sms', $channels, true),
            RuntimeException::class,
            'AUTH_OTP_CHANNELS không được chứa sms ở production (chưa có nhà cung cấp SMS — S9).'
        );
    }

    private function guardStatefulDomains(): void
    {
        foreach ((array) config('sanctum.stateful') as $domain) {
            $normalized = mb_strtolower((string) $domain);

            throw_if(
                str_contains($normalized, 'localhost') || str_contains($normalized, '127.0.0.1'),
                RuntimeException::class,
                "SANCTUM_STATEFUL_DOMAINS chứa '{$domain}' — không được có localhost/127.0.0.1 ở production (M4)."
            );
        }
    }

    private function guardTrustedProxies(): void
    {
        $proxies = array_map('trim', explode(',', (string) config('app.trusted_proxies')));

        throw_if(
            in_array('*', $proxies, true),
            RuntimeException::class,
            'TRUSTED_PROXIES không được là "*" ở production (M4, S10).'
        );
    }

    /**
     * S4 — cấm cổng thanh toán `fake`/sandbox lọt production (không phân biệt
     * hoa/thường); endpoint MoMo phải khớp ĐÚNG allowlist (không dùng blocklist
     * kiểu `str_contains('test-payment')`, dễ bỏ sót biến thể khác).
     */
    private function guardPayments(): void
    {
        $gateways = array_map(
            static fn ($gateway) => mb_strtolower((string) $gateway),
            (array) config('payments.enabled_gateways', [])
        );

        throw_if(
            in_array('fake', $gateways, true),
            RuntimeException::class,
            'FakeGateway bị cấm ở production (S4).'
        );

        if (! in_array('momo', $gateways, true)) {
            return;
        }

        $endpoint = (string) config('payments.gateways.momo.endpoint');
        $parts = parse_url($endpoint);
        $isAllowedMomoEndpoint = ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'payment.momo.vn';

        throw_if(
            ! $isAllowedMomoEndpoint,
            RuntimeException::class,
            'MOMO_ENDPOINT phải đúng https://payment.momo.vn ở production (S4, M4).'
        );
    }
}
