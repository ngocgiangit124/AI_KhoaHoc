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

    /** C4-M2: tên môi trường hợp lệ (so khớp chính xác, chữ thường, không khoảng trắng/chú thích). */
    private const VALID_ENVIRONMENTS = ['production', 'staging', 'local', 'testing'];

    /**
     * C4-M2: biến môi trường quan trọng không được dính chú thích cuối dòng (`KEY=value   # ghi chú`): docker `--env-file`
     * và systemd `EnvironmentFile` giữ nguyên phần ghi chú trong giá trị, còn phpdotenv thì cắt đi nên lệch nhau.
     *
     * @var list<string>
     */
    private const ENV_KEYS_NO_INLINE_COMMENT = [
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_API_HOST', 'APP_ADMIN_API_HOST', 'FRONTEND_URL', 'ADMIN_URL',
        'STATIC_URL', 'SANCTUM_STATEFUL_DOMAINS', 'TRUSTED_PROXIES', 'SESSION_DRIVER', 'SESSION_ENCRYPT', 'SESSION_DOMAIN',
        'SESSION_SECURE_COOKIE', 'SESSION_COOKIE', 'SESSION_ADMIN_COOKIE', 'INTERNAL_API_TOKEN', 'INTERNAL_API_REQUIRED',
        'CAPTCHA_DRIVER', 'TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET', 'AUTH_OTP_CHANNELS', 'AUTH_OTP_E2E_RELAXED',
        'PAYMENT_GATEWAYS', 'FEATURE_PAID_CHECKOUT', 'FEATURE_STAFF_MFA', 'MOMO_ENDPOINT', 'MOMO_PAY_URL_HOSTS',
        'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY', 'MOMO_PARTNER_CODE', 'VIDEO_PROVIDER', 'VIDEO_ENABLED_PROVIDERS',
        'VIDEOLAB_ENABLED', 'VIDEOLAB_API_KEY', 'VIDEOLAB_TOKEN_KEY', 'VIDEOLAB_WEBHOOK_SECRET', 'VIDEOLAB_PUBLIC_URL',
        'VIDEOLAB_ACCEL_REDIRECT', 'DB_CONNECTION', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST',
        'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PREFIX', 'CACHE_PREFIX', 'QUEUE_CONNECTION', 'CACHE_STORE',
    ];

    public function check(): void
    {
        // C4-L4/R3: ép tắt debug NGAY ĐẦU (trước mọi kiểm, kể cả APP_ENV hợp lệ) để exception handler không render trang
        // debug (stack trace, tên class, kết nối DB) cho route ngoài `api/*`, ngay cả khi APP_ENV viết sai (`prod`).
        $environment = app()->environment();
        $isDev = in_array($environment, ['local', 'testing'], true);
        $debugWasOn = (bool) config('app.debug');

        if (! $isDev) {
            config(['app.debug' => false]);
        }

        // C4-M2: APP_ENV phải đúng một trong 4 giá trị hợp lệ. `prod`, `Production`, `stage`, `uat`, hay giá trị dính chú
        // thích (`production   # ...`) đều bị chặn thay vì lặng lẽ tắt các kiểm chỉ dành cho `production`.
        throw_unless(
            in_array($environment, self::VALID_ENVIRONMENTS, true),
            RuntimeException::class,
            'APP_ENV phải đúng "production" hoặc "staging" (chữ thường, không khoảng trắng/chú thích); local/testing chỉ dùng cho máy dev.'
        );

        // T31 (R2 review): áp cho MỌI môi trường trừ local/testing (giống VideoLabServiceProvider). Allowlist endpoint/
        // pay_url MoMo chỉ ép ở đúng `production` (staging dùng sandbox MoMo).
        if ($isDev) {
            return;
        }

        throw_if(
            $debugWasOn,
            RuntimeException::class,
            'APP_DEBUG phải là false ở production/staging (M4).'
        );

        $this->guardEnvWithoutInlineComments();

        throw_if(
            ! config('session.secure'),
            RuntimeException::class,
            'SESSION_SECURE_COOKIE phải bật (true) ở production/staging (M4).'
        );

        // C4-M1: payload phiên trong Redis phải mã hoá (giảm tác động khi Redis bị đọc; phiên không giả mạo được chỉ bằng
        // quyền ghi Redis).
        throw_unless(
            config('session.encrypt'),
            RuntimeException::class,
            'SESSION_ENCRYPT phải bật (true) ở production/staging (C4-M1).'
        );

        $this->guardCaptcha();
        $this->guardOtpChannels();
        $this->guardUrlsHttps();
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

    /**
     * C4-M2 — đọc GIÁ TRỊ THÔ của biến môi trường (không qua phpdotenv): nếu còn chú thích cuối dòng hoặc khoảng trắng
     * thừa thì nơi nạp env (docker/systemd/Supervisor) đã đưa chú thích vào giá trị.
     */
    private function guardEnvWithoutInlineComments(): void
    {
        foreach (self::ENV_KEYS_NO_INLINE_COMMENT as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            throw_if(
                preg_match('/\s#/', $value) === 1 || trim($value) !== $value,
                RuntimeException::class,
                "Biến môi trường {$key} chứa chú thích cuối dòng (' #') hoặc khoảng trắng thừa: đưa chú thích lên dòng riêng (C4-M2)."
            );
        }
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

        // C4-M2: chỉ chấp nhận hex (đầu ra của `openssl rand -hex 32`): loại chú thích/placeholder lọt vào giá trị.
        throw_if(
            is_string($token) && $token !== '' && ! ctype_xdigit($token),
            RuntimeException::class,
            'INTERNAL_API_TOKEN phải là chuỗi hex (sinh bằng openssl rand -hex 32), không chứa khoảng trắng/chú thích.'
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

    /** C4-L5 — APP_URL/FRONTEND_URL/ADMIN_URL đã đặt tên miền thật thì phải dùng https (URL local mặc định bị bỏ qua). */
    private function guardUrlsHttps(): void
    {
        foreach (['APP_URL' => 'app.url', 'FRONTEND_URL' => 'app.frontend_url', 'ADMIN_URL' => 'app.admin_url'] as $name => $key) {
            $url = (string) config($key);

            throw_if(
                $this->hostWithPort($url) !== null && ! str_starts_with(mb_strtolower($url), 'https://'),
                RuntimeException::class,
                "{$name} phải dùng https ở production/staging (C4-L5)."
            );
        }
    }

    /**
     * C4-L5 — ALLOWLIST: mỗi mục phải là tên miền hợp lệ (không `*`, không `localhost`/IP/`::1`/`0.0.0.0`), và khi
     * FRONTEND_URL/ADMIN_URL đã đặt tên miền thật thì tập stateful phải KHỚP CHÍNH XÁC host của hai URL đó (loại
     * `vitaminvui.vn.evil.com`, tên miền staging lạc sang production...).
     */
    private function guardStatefulDomains(): void
    {
        $domains = [];

        foreach ((array) config('sanctum.stateful') as $domain) {
            $normalized = mb_strtolower(trim((string) $domain));

            $isHostname = preg_match('/^(?=.{1,253}(:\d{1,5})?$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?(:\d{1,5})?$/', $normalized) === 1;
            $isLocal = preg_match('/(^|\.)(localhost|local|test|invalid|example)(:\d+)?$/', $normalized) === 1;

            throw_if(
                ! $isHostname || $isLocal,
                RuntimeException::class,
                "SANCTUM_STATEFUL_DOMAINS chứa '{$domain}' — chỉ nhận tên miền thật (không *, localhost, IP, ::1) ở production/staging (M4, C4-L5)."
            );

            $domains[] = $normalized;
        }

        $expected = array_map($this->hostWithPort(...), [(string) config('app.frontend_url'), (string) config('app.admin_url')]);

        // Cả hai URL còn là giá trị local mặc định (chưa cấu hình): không có gì để so khớp ở đây (checklist ép
        // FRONTEND_URL/ADMIN_URL là https). Chỉ một trong hai là local thì cấu hình sai rõ ràng: chặn.
        if ($expected === [null, null]) {
            return;
        }

        throw_if(
            in_array(null, $expected, true),
            RuntimeException::class,
            'FRONTEND_URL và ADMIN_URL phải cùng là tên miền thật ở production/staging (C4-L5).'
        );

        $expected = array_values(array_unique($expected));
        sort($expected);
        $domains = array_values(array_unique($domains));
        sort($domains);

        throw_if(
            $domains !== $expected,
            RuntimeException::class,
            'SANCTUM_STATEFUL_DOMAINS phải đúng host của FRONTEND_URL và ADMIN_URL ('.implode(',', $expected).'), không thừa không thiếu (C4-L5).'
        );
    }

    /** Host (kèm cổng nếu có) của URL; null nếu là loopback/local (chưa cấu hình thật) hoặc không đọc được. */
    private function hostWithPort(string $url): ?string
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || preg_match('/(^|\.)(localhost|local|test)$/', $host) === 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
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
