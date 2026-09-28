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
        $this->guardStatefulDomains();
        $this->guardTrustedProxies();
        $this->guardPayments();
        $this->guardOtpChannels();
    }

    /**
     * M3 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY chỉ cấm ĐÚNG
     * chuỗi `fake` (blocklist): `Turnstile`, `TURNSTILE`, `turnstile ` (thừa
     * khoảng trắng), chuỗi rỗng, `none`... đều LỌT qua guard này (không ném),
     * nhưng binding trong `AppServiceProvider` (trước khi sửa M3) coi mọi giá
     * trị khác `'turnstile'` là `fake` — production "không có captcha thật"
     * mà không có cảnh báo nào lúc boot. Đổi hẳn sang ALLOWLIST: bắt buộc
     * ĐÚNG (phân biệt hoa/thường) `turnstile`, và bắt buộc có cả secret lẫn
     * site key (thiếu 1 trong 2 thì Turnstile không thể xác minh được).
     */
    private function guardCaptcha(): void
    {
        $driver = (string) config('captcha.driver');

        throw_unless(
            $driver === 'turnstile',
            RuntimeException::class,
            "CAPTCHA_DRIVER phải đúng 'turnstile' ở production (M3), hiện là: '{$driver}'."
        );

        throw_if(
            blank(config('services.turnstile.secret')),
            RuntimeException::class,
            'Thiếu TURNSTILE_SECRET ở production (M3).'
        );

        throw_if(
            blank(config('services.turnstile.site_key')),
            RuntimeException::class,
            'Thiếu TURNSTILE_SITE_KEY ở production (M3).'
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

    /**
     * T04 (S9, S11) — chưa có nhà cung cấp SMS thật: `sms` chỉ là kênh mô
     * phỏng (`LogSmsOtpSender`, ghi log `***`) dùng để dev/test cục bộ. Cấm
     * tuyệt đối ở production (blocklist đơn giá trị là đủ — không giống
     * captcha/thanh toán có nhiều biến thể viết sai chính tả cần allowlist).
     * `email` là kênh DUY NHẤT có nhà cung cấp thật nên bắt buộc phải có.
     */
    private function guardOtpChannels(): void
    {
        $channels = (array) config('auth.otp.channels');

        throw_unless(
            in_array('email', $channels, true),
            RuntimeException::class,
            'AUTH_OTP_CHANNELS phải luôn có "email" ở production.'
        );

        throw_if(
            in_array('sms', $channels, true),
            RuntimeException::class,
            'AUTH_OTP_CHANNELS không được có "sms" ở production — chưa có nhà cung cấp SMS thật (S9, S11).'
        );
    }
}
