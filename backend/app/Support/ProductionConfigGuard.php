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
    private const VIDEOLAB_KEY_MIN_LENGTH = 32;

    public function check(): void
    {
        // T31 (R2 review): áp cho MỌI môi trường trừ local/testing (giống VideoLabServiceProvider), nên `Production`,
        // `prod`, `stage`, `uat`... đều bị kiểm. Allowlist endpoint/pay_url MoMo chỉ ép ở đúng `production`
        // (staging dùng sandbox MoMo).
        if (app()->environment('local', 'testing')) {
            return;
        }

        throw_if(
            (bool) config('app.debug'),
            RuntimeException::class,
            'APP_DEBUG phải là false ở production/staging (M4).'
        );

        throw_if(
            ! config('session.secure'),
            RuntimeException::class,
            'SESSION_SECURE_COOKIE phải bật (true) ở production/staging (M4).'
        );

        $this->guardCaptcha();
        $this->guardOtpChannels();
        $this->guardStatefulDomains();
        $this->guardTrustedProxies();
        $this->guardPayments();
        $this->guardVideo();
        $this->guardInternalToken();
        $this->guardOtpRelaxed();
        $this->guardVideoLabSecrets();
        $this->guardPaidCheckout();
        $this->guardStaffMfa();
    }

    /** Cụm 1 L1 — MFA staff không được tắt ở production/staging (cờ chỉ để e2e local). */
    private function guardStaffMfa(): void
    {
        throw_if(
            ! config('features.staff_mfa'),
            RuntimeException::class,
            'FEATURE_STAFF_MFA phải là true ở production/staging (cụm 1 L1).'
        );
    }

    /**
     * MF1-1 — lớp thứ 2: `config/auth.php` đã ép e2e_relaxed=false ngoài local/testing; guard phòng khi config đó bị đổi.
     * Không phủ được trường hợp đặt nhầm APP_ENV=local trên server thật (kiểm tay trong checklist).
     */
    private function guardOtpRelaxed(): void
    {
        throw_if(
            (bool) config('auth.otp.e2e_relaxed'),
            RuntimeException::class,
            'AUTH_OTP_E2E_RELAXED phải là false ở production/staging (MF1-1).'
        );
    }

    /**
     * T31 (review T12 R7) — khoá VideoLab phải đặt riêng, ≥ 32 ký tự, khi VideoLab bật. Config trả null khi
     * thiếu khoá ngoài local/testing (không suy từ APP_KEY).  Lớp thứ 2 ngoài VideoLabServiceProvider (provider đó còn ép VIDEOLAB_PUBLIC_URL https).
     */
    private function guardVideoLabSecrets(): void
    {
        if (! config('videolab.enabled')) {
            return;
        }

        foreach (['api_key', 'token_key', 'webhook_secret'] as $key) {
            $value = config("videolab.{$key}");

            throw_if(
                ! is_string($value) || mb_strlen($value) < self::VIDEOLAB_KEY_MIN_LENGTH,
                RuntimeException::class,
                'VIDEOLAB_'.mb_strtoupper($key).' phải đặt riêng và dài tối thiểu '.self::VIDEOLAB_KEY_MIN_LENGTH.' ký tự (openssl rand -hex 32).'
            );
        }
    }

    /** Bật thanh toán có tiền mà không có cổng nào bật = checkout lỗi giữa chừng: chặn sớm. */
    private function guardPaidCheckout(): void
    {
        throw_if(
            config('features.paid_checkout') && (array) config('payments.enabled_gateways', []) === [],
            RuntimeException::class,
            'FEATURE_PAID_CHECKOUT=true nhưng PAYMENT_GATEWAYS rỗng.'
        );

        // Cụm 3 L1: chưa có route IPN (T19) thì HS trả tiền xong không được ghi danh. `payments.ipn_ready` là hằng trong
        // config/payments.php (không phải env), mặc định false. TODO(T19): khi route webhook + đối soát (T20) xong,
        // đổi hằng đó thành true (hoặc gỡ điều kiện này) cùng lúc bật FEATURE_PAID_CHECKOUT ở production.
        throw_if(
            config('features.paid_checkout') && ! config('payments.ipn_ready'),
            RuntimeException::class,
            'FEATURE_PAID_CHECKOUT=true nhưng chưa có route IPN/đối soát (T19/T20): payments.ipn_ready=false.'
        );
    }

    /** Token SSR (nếu bật) phải đủ dài; để trống = tắt (catalog throttle theo IP kết nối). */
    private function guardInternalToken(): void
    {
        $token = config('internal.ssr_token');

        throw_if(
            config('internal.required') && (! is_string($token) || $token === ''),
            RuntimeException::class,
            'INTERNAL_API_TOKEN bắt buộc khi INTERNAL_API_REQUIRED=true ở production.'
        );

        throw_if(
            is_string($token) && $token !== '' && mb_strlen($token) < (int) config('internal.ssr_token_min_length'),
            RuntimeException::class,
            'INTERNAL_API_TOKEN phải dài tối thiểu '.config('internal.ssr_token_min_length').' ký tự (sinh bằng openssl rand -hex 32).'
        );
    }

    private function guardCaptcha(): void
    {
        $driver = mb_strtolower((string) config('captcha.driver'));

        throw_if(
            $driver === 'fake',
            RuntimeException::class,
            'CAPTCHA_DRIVER=fake bị cấm ở production/staging (M4, T31).'
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

        // minor-fixes-3 R2: request HTTP không có proxy tin cậy thì `$request->ip()` luôn là IP của Nginx/LB, nên mọi
        // học sinh dùng chung một bộ đếm theo IP (trần mã giảm giá, đăng nhập) và khoá lẫn nhau. Tiến trình console
        // (queue:work của worker-video, scheduler, artisan) không nhận HTTP nên được miễn: file mẫu worker-video
        // để `TRUSTED_PROXIES=` rỗng. `app.trusted_proxies_console_exempt` chỉ để test tắt được ngoại lệ này.
        $exempt = app()->runningInConsole() && (bool) config('app.trusted_proxies_console_exempt', true);

        throw_if(
            ! $exempt && array_filter($proxies, static fn (string $p): bool => $p !== '') === [],
            RuntimeException::class,
            'TRUSTED_PROXIES phải liệt kê IP của Nginx/load balancer ở production/staging (không được rỗng): nếu rỗng, mọi người dùng chung một IP với proxy (R2).'
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

        // Staging được dùng sandbox MoMo; chỉ production bị ép endpoint thật.
        if (! app()->isProduction() || ! in_array('momo', $gateways, true)) {
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

        // T17-2 — pay_url chỉ được trỏ tới host MoMo thật ở production.
        throw_if(
            array_map('mb_strtolower', (array) config('payments.gateways.momo.pay_url_hosts', ['payment.momo.vn'])) !== ['payment.momo.vn'],
            RuntimeException::class,
            'MOMO_PAY_URL_HOSTS chỉ được là payment.momo.vn ở production (S23, T17-2).'
        );
    }

    /** T11 — cấm nhà cung cấp video `fake` lọt production. */
    private function guardVideo(): void
    {
        $providers = array_map(
            static fn ($provider) => mb_strtolower((string) $provider),
            [...(array) config('video.enabled_providers', []), (string) config('video.provider')]
        );

        throw_if(
            in_array('fake', $providers, true),
            RuntimeException::class,
            'FakeVideoProvider bị cấm ở production (ADR-002).'
        );
    }
}
