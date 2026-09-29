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
        $this->guardOtpMailer();
        $this->guardOtpThresholds();
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
     * T04 security review L2 [Low] — TRƯỚC ĐÂY là blocklist (chỉ cấm đúng
     * chuỗi `sms`): `AUTH_OTP_CHANNELS=email,SMS` hay `email,push` vẫn "boot"
     * được (không cấm kênh lạ khác `sms`), dù `OtpSenderManager` sau đó ném
     * lỗi 500 thật khi có request đi vào kênh lạ — không lộ mã nhưng biến
     * thành đường tạo lỗi 500 vô ích. Đổi hẳn sang ALLOWLIST, so khớp CHÍNH
     * XÁC (phân biệt hoa/thường) — đúng tinh thần `guardCaptcha()` (cũng từ
     * chối `Turnstile` viết hoa, không tự chuẩn hoá): production CHỈ được
     * đúng 1 kênh `email` (kênh DUY NHẤT có nhà cung cấp thật — S9, S11).
     * `AUTH_OTP_CHANNELS` đã được `config/auth.php` `trim()` từng phần tử lúc
     * đọc env, nên không cần trim lại ở đây.
     */
    private function guardOtpChannels(): void
    {
        $channels = array_values((array) config('auth.otp.channels'));

        throw_unless(
            $channels === ['email'],
            RuntimeException::class,
            "AUTH_OTP_CHANNELS phải đúng 'email' ở production (chưa có nhà cung cấp SMS/kênh khác thật — S9, S11), hiện là: '".implode(',', $channels)."'."
        );
    }

    /**
     * T04 security review L2 [Low] — nếu `MAIL_MAILER` bị để `log`/`array` ở
     * production (cấu hình rất hay bị copy nguyên từ local/testing), MỌI mã
     * OTP sẽ bị ghi RÕ vào `storage/logs` (driver `log`) hoặc chỉ nằm trong bộ
     * nhớ rồi mất (driver `array`, không gửi được gì) — đi ngược S21 (không
     * bao giờ ghi mã OTP ra log).
     */
    private function guardOtpMailer(): void
    {
        $mailer = (string) config('mail.default');

        throw_if(
            in_array($mailer, ['log', 'array'], true),
            RuntimeException::class,
            "MAIL_MAILER không được là '{$mailer}' ở production — mã OTP sẽ bị ghi rõ vào log hoặc không gửi được (S21)."
        );
    }

    /**
     * T04 security review L2 [Low] — `AUTH_OTP_*` không có kiểm biên: có thể
     * đặt `cooldown=0` (mất tác dụng chống spam gửi), `max_attempts_per_code`
     * quá lớn (cột `attempts` là `tinyint unsigned`, > 255 gây lỗi SQL) hoặc
     * bằng 0 (không ai xác thực được), hay `max_per_day`/`max_verify_per_day`
     * bất thường mà không có cảnh báo nào lúc boot.
     */
    private function guardOtpThresholds(): void
    {
        $cooldown = (int) config('auth.otp.cooldown_seconds');
        $maxAttempts = (int) config('auth.otp.max_attempts_per_code');
        $maxPerDay = (int) config('auth.otp.max_per_day');
        $maxVerifyPerDay = (int) config('auth.otp.max_verify_per_day');
        $ttlMinutes = (int) config('auth.otp.ttl_minutes');

        throw_unless(
            $cooldown >= 30,
            RuntimeException::class,
            'AUTH_OTP_COOLDOWN_SECONDS phải ≥ 30 giây ở production (S9).'
        );

        throw_unless(
            $maxAttempts >= 1 && $maxAttempts <= 10,
            RuntimeException::class,
            'AUTH_OTP_MAX_ATTEMPTS_PER_CODE phải trong khoảng 1-10 ở production (cột attempts là tinyint — S9).'
        );

        throw_unless(
            $maxPerDay >= 1 && $maxPerDay <= 20,
            RuntimeException::class,
            'AUTH_OTP_MAX_PER_DAY phải trong khoảng 1-20 ở production (S9).'
        );

        throw_unless(
            $maxVerifyPerDay >= 1 && $maxVerifyPerDay <= 50,
            RuntimeException::class,
            'AUTH_OTP_MAX_VERIFY_PER_DAY phải trong khoảng 1-50 ở production (S9).'
        );

        throw_unless(
            $ttlMinutes >= 1,
            RuntimeException::class,
            'AUTH_OTP_TTL_MINUTES phải ≥ 1 phút ở production.'
        );
    }
}
