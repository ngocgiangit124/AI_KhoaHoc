<?php

namespace App\Support;

use App\Services\Video\Providers\BunnyStreamProvider;
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
        'PAYMENT_GATEWAYS', 'FEATURE_PAID_CHECKOUT', 'FEATURE_MANUAL_PAYMENT', 'ORDERS_MANUAL_PENDING_TTL_HOURS',
        'ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 'ORDERS_MANUAL_PER_DAY', 'ORDERS_CUSTOMER_NOTE_RETENTION_DAYS', 'ORDERS_MANUAL_NOTIFY_EMAILS', 'PAYMENT_CONTACT_PHONE',
        'PAYMENT_CONTACT_ZALO_URL', 'PAYMENT_CONTACT_EMAIL', 'PAYMENT_CONTACT_HOURS', 'FEATURE_STAFF_MFA', 'MOMO_ENDPOINT', 'MOMO_PAY_URL_HOSTS',
        'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY', 'MOMO_PARTNER_CODE', 'VIDEO_PROVIDER', 'VIDEO_ENABLED_PROVIDERS',
        'VIDEOLAB_ENABLED', 'VIDEOLAB_API_KEY', 'VIDEOLAB_TOKEN_KEY', 'VIDEOLAB_WEBHOOK_SECRET', 'VIDEOLAB_PUBLIC_URL',
        'VIDEOLAB_ACCEL_REDIRECT', 'BUNNY_LIBRARY_ID', 'BUNNY_API_KEY', 'BUNNY_CDN_HOST', 'BUNNY_TOKEN_KEY', 'BUNNY_WEBHOOK_TOKEN', 'BUNNY_API_BASE',
        'BUNNY_TUS_ENDPOINT', 'DB_CONNECTION', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST',
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
        $this->guardStaticUrl();
        $this->guardStatefulDomains();
        $this->guardTrustedProxies();
        $this->guardPayments();
        $this->guardVideo();
        $this->guardBunny();
        $this->guardInternalToken();
        $this->guardOtpRelaxed();
        $this->guardVideoLabSecrets();
        $this->guardPaidCheckout();
        $this->guardManualPayment();
        $this->guardCustomerNoteRetention();
        $this->guardStaffMfa();
        $this->guardPolicyVersion();
        $this->guardParentNotices();
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

    /**
     * S2 (security T29): khoá HMAC của danh sách huỷ nhận + token huỷ nhận PHẢI là khoá riêng (≥ 32 byte), không dẫn xuất từ
     * APP_KEY. KHÔNG ĐƯỢC XOAY sau khi chạy thật: xoay khoá làm danh sách `parent_notice_suppressions` và mọi token đã gửi mất
     * hiệu lực, phụ huynh đã huỷ nhận sẽ lại nhận thư. Trần thư/địa chỉ phải trong 1..20.
     */
    private function guardParentNotices(): void
    {
        $key = config('privacy.notice_token_key');

        throw_if(
            ! is_string($key) || strlen($key) < 32,
            RuntimeException::class,
            'PRIVACY_NOTICE_TOKEN_KEY phải đặt riêng, tối thiểu 32 byte, không dẫn xuất từ APP_KEY và KHÔNG được xoay (T29).'
        );

        $cap = (int) config('privacy.parent_notice_daily_cap_per_address');

        throw_if(
            $cap < 1 || $cap > 20,
            RuntimeException::class,
            'PRIVACY_PARENT_NOTICE_DAILY_CAP phải trong khoảng 1..20 (T29).'
        );
    }

    /** ADR-006 (T29): phiên bản chính sách gắn vào mọi bản ghi `consents` và `/config/public` — không được rỗng. */
    private function guardPolicyVersion(): void
    {
        throw_if(
            trim((string) config('privacy.policy_version')) === '',
            RuntimeException::class,
            'PRIVACY_POLICY_VERSION không được rỗng ở production/staging (T29).'
        );
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

    /**
     * US-022 (ADR-007 §11): bật thanh toán thủ công thì học sinh phải biết liên hệ ở đâu và Quản trị viên phải nhận được thông
     * báo đơn mới. Guard của `FEATURE_PAID_CHECKOUT` + `ipn_ready` (MoMo) giữ nguyên, độc lập.
     */
    private function guardManualPayment(): void
    {
        if (! config('features.manual_payment')) {
            return;
        }

        $contact = (array) config('orders.manual.contact', []);
        $phone = $this->trimmedString($contact['phone'] ?? null);
        $zalo = $this->trimmedString($contact['zalo_url'] ?? null);
        $email = $this->trimmedString($contact['email'] ?? null);
        $hours = $this->trimmedString($contact['hours'] ?? null);

        throw_if(
            $phone === null && $zalo === null && $email === null,
            RuntimeException::class,
            'FEATURE_MANUAL_PAYMENT=true nhưng chưa có kênh liên hệ nào (PAYMENT_CONTACT_PHONE / PAYMENT_CONTACT_ZALO_URL / PAYMENT_CONTACT_EMAIL).'
        );

        throw_if(
            $zalo !== null && preg_match('#^https://zalo\.me/[A-Za-z0-9._-]+$#', $zalo) !== 1,
            RuntimeException::class,
            'PAYMENT_CONTACT_ZALO_URL phải có dạng https://zalo.me/<định-danh>.'
        );

        throw_if(
            $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false,
            RuntimeException::class,
            'PAYMENT_CONTACT_EMAIL không phải địa chỉ email hợp lệ.'
        );

        throw_if(
            $phone !== null && preg_match('/^\+?[0-9 .-]{8,20}$/', $phone) !== 1,
            RuntimeException::class,
            'PAYMENT_CONTACT_PHONE không phải số điện thoại hợp lệ (chỉ chữ số, khoảng trắng, dấu chấm, gạch ngang, + đầu; 8..20 ký tự).'
        );

        throw_if(
            $phone !== null && strlen((string) preg_replace('/\D/', '', $phone)) < 8,
            RuntimeException::class,
            'PAYMENT_CONTACT_PHONE phải có ít nhất 8 chữ số.'
        );

        throw_if(
            $hours !== null && (mb_strlen($hours) > 100 || preg_match('/[<>\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $hours) === 1),
            RuntimeException::class,
            'PAYMENT_CONTACT_HOURS dài tối đa 100 ký tự và không chứa HTML/ký tự điều khiển.'
        );

        $notify = (array) config('orders.manual.notify_emails', []);

        throw_if(
            $notify === [],
            RuntimeException::class,
            'FEATURE_MANUAL_PAYMENT=true nhưng ORDERS_MANUAL_NOTIFY_EMAILS rỗng: không ai nhận thông báo đơn mới.'
        );

        foreach ($notify as $address) {
            throw_if(
                ! is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false,
                RuntimeException::class,
                'ORDERS_MANUAL_NOTIFY_EMAILS chứa địa chỉ email không hợp lệ.'
            );
        }

        // R5: config đã ép (int) nên `abc` thành 0 và lọt; kiểm chuỗi THÔ của env phải là số nguyên không dấu.
        foreach (['ORDERS_MANUAL_PENDING_TTL_HOURS', 'ORDERS_MANUAL_APPROVAL_WINDOW_DAYS', 'ORDERS_MANUAL_PER_DAY'] as $envKey) {
            $raw = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);

            throw_if(
                is_string($raw) && $raw !== '' && preg_match('/^[0-9]{1,6}$/', $raw) !== 1,
                RuntimeException::class,
                "{$envKey} phải là số nguyên (không dấu, không chữ)."
            );
        }

        foreach ([
            'ORDERS_MANUAL_PENDING_TTL_HOURS' => ['orders.manual.pending_ttl_hours', 1, 168],
            'ORDERS_MANUAL_APPROVAL_WINDOW_DAYS' => ['orders.manual.approval_window_days', 0, 90],
            'ORDERS_MANUAL_PER_DAY' => ['orders.manual.per_day', 1, 50],
        ] as $env => [$key, $min, $max]) {
            $value = config($key);

            throw_if(
                ! is_int($value) || $value < $min || $value > $max,
                RuntimeException::class,
                "{$env} phải là số nguyên trong khoảng {$min}..{$max}."
            );
        }
    }

    /**
     * T38-1: số ngày giữ `customer_note` (áp cho mọi đơn, độc lập cờ manual). Kiểm chuỗi THÔ như R5 vì config đã ép (int).
     */
    private function guardCustomerNoteRetention(): void
    {
        $envKey = 'ORDERS_CUSTOMER_NOTE_RETENTION_DAYS';
        $raw = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);

        throw_if(
            is_string($raw) && $raw !== '' && preg_match('/^[0-9]{1,6}$/', $raw) !== 1,
            RuntimeException::class,
            "{$envKey} phải là số nguyên (không dấu, không chữ)."
        );

        $value = config('orders.manual.customer_note_retention_days');

        throw_if(
            ! is_int($value) || $value < 30 || $value > 3650,
            RuntimeException::class,
            "{$envKey} phải là số nguyên trong khoảng 30..3650."
        );
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
     * Nhãn cấp 2 thường gặp dưới ccTLD 2 chữ (`com.vn`, `edu.vn`, `co.uk`...): heuristic thay cho Public Suffix List (không cài
     * package mới). GIỚI HẠN: chỉ đúng với các đuôi dạng `<sld>.<cc>` trong danh sách này; đuôi lạ (ví dụ `city.kawasaki.jp`)
     * bị coi là 2 nhãn cuối. Đã đủ cho tên miền của dự án (`.vn`, `.net`, `.com`); sai thì lỗi nghiêng về an toàn ở phần
     * so khớp cha/con bên dưới.
     */
    private const SECOND_LEVEL_LABELS = ['com', 'net', 'org', 'edu', 'gov', 'ac', 'info', 'biz', 'name', 'pro', 'health', 'int', 'co'];

    /**
     * US-020 (T36, S2) — miền tĩnh phục vụ ảnh chân dung CÔNG KHAI của giáo viên (và thumbnail khóa), nên phải là miền
     * riêng KHÔNG cùng site với web/api/admin: `STATIC_URL` bắt buộc đặt, dùng https, host là tên miền (không phải IP) và
     * KHÁC registrable domain (eTLD+1, heuristic) của `APP_URL`, `FRONTEND_URL`, `ADMIN_URL` và host `api`/`admin-api`
     * (`APP_API_HOST`, `APP_ADMIN_API_HOST`); đồng thời không trùng hoặc là miền cha/con của chúng, và không nằm dưới
     * `SESSION_DOMAIN`. Host được chuẩn hoá trước khi so (chữ thường, bỏ dấu chấm cuối, đổi dấu chấm toàn chiều rộng/CJK
     * thành `.`, IDNA → punycode). Kiểm thêm câu chữ đồng ý (`teacher_profile.consent_version`) khác rỗng.
     */
    private function guardStaticUrl(): void
    {
        throw_if(
            trim((string) config('teacher_profile.consent_version')) === '',
            RuntimeException::class,
            'teacher_profile.consent_version phải khác rỗng (bằng chứng đồng ý công khai hồ sơ giáo viên, US-020).'
        );

        $url = trim((string) config('app.static_url'));

        throw_if(
            $url === '',
            RuntimeException::class,
            'STATIC_URL phải được đặt ở production/staging (miền tĩnh riêng cho ảnh công khai, US-020).'
        );

        throw_unless(
            str_starts_with(mb_strtolower($url), 'https://'),
            RuntimeException::class,
            'STATIC_URL phải dùng https ở production/staging (US-020).'
        );

        $host = $this->normalizeHost((string) parse_url($url, PHP_URL_HOST));

        throw_if(
            $host === '',
            RuntimeException::class,
            'STATIC_URL không đọc được tên miền (US-020).'
        );

        throw_if(
            filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_contains($host, ':'),
            RuntimeException::class,
            'STATIC_URL phải là tên miền, không được là địa chỉ IP (US-020).'
        );

        $others = [
            'APP_URL' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'FRONTEND_URL' => (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST),
            'ADMIN_URL' => (string) parse_url((string) config('app.admin_url'), PHP_URL_HOST),
            'APP_API_HOST' => (string) config('app.api_host'),
            'APP_ADMIN_API_HOST' => (string) config('app.admin_api_host'),
        ];
        $staticSite = $this->registrableDomain($host);

        foreach ($others as $name => $raw) {
            $other = $this->normalizeHost($raw);

            if ($other === '') {
                continue;
            }

            throw_if(
                $host === $other
                    || str_ends_with($host, '.'.$other)
                    || str_ends_with($other, '.'.$host)
                    || $staticSite === $this->registrableDomain($other),
                RuntimeException::class,
                "STATIC_URL không được cùng site (cùng tên miền đăng ký, hoặc miền cha/con) với {$name}: miền tĩnh không được chia sẻ cookie/same-site (S2, US-020)."
            );
        }

        $cookieDomain = $this->normalizeHost((string) config('session.domain'));

        throw_if(
            $cookieDomain !== '' && $cookieDomain !== 'null' && ($host === $cookieDomain || str_ends_with($host, '.'.$cookieDomain)),
            RuntimeException::class,
            'SESSION_DOMAIN đang phủ cả host của STATIC_URL: cookie phiên sẽ gửi sang miền tĩnh (S2, US-020).'
        );
    }

    /** Chữ thường, bỏ khoảng trắng, đổi dấu chấm toàn chiều rộng (U+FF0E), CJK (U+3002), nửa chiều rộng (U+FF61) thành `.`, bỏ dấu chấm cuối/đầu, IDNA → ASCII. */
    private function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));
        $host = str_replace(["\u{FF0E}", "\u{3002}", "\u{FF61}"], '.', $host);
        $host = trim($host, '.');

        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) && $ascii !== '' ? $ascii : $host;
        }

        return $host;
    }

    /** Registrable domain (eTLD+1) theo heuristic: 2 nhãn cuối, hoặc 3 nhãn khi đuôi là `<sld>.<cc 2 chữ>` (xem SECOND_LEVEL_LABELS). */
    private function registrableDomain(string $host): string
    {
        $labels = explode('.', $host);
        $count = count($labels);

        if ($count <= 2) {
            return $host;
        }

        $tld = $labels[$count - 1];
        $sld = $labels[$count - 2];

        if (strlen($tld) === 2 && in_array($sld, self::SECOND_LEVEL_LABELS, true)) {
            return implode('.', array_slice($labels, -3));
        }

        return implode('.', array_slice($labels, -2));
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

    /**
     * US-021 BR11 — khi Bunny đang bật (mặc định hoặc trong allowlist) phải đủ BUNNY_LIBRARY_ID/API_KEY/CDN_HOST/TOKEN_KEY;
     * CDN host là https, là tên miền (không IP, không đường dẫn), KHÁC host của app/web/admin/api/admin-api và không nằm
     * dưới SESSION_DOMAIN (cookie phiên không được gửi sang CDN); api_base/tus_endpoint là https (khoá API đi trong header).
     * Thông báo chỉ nêu TÊN biến, không bao giờ in giá trị.
     */
    private function guardBunny(): void
    {
        $providers = array_map(
            static fn ($provider) => mb_strtolower((string) $provider),
            [...(array) config('video.enabled_providers', []), (string) config('video.provider')]
        );

        if (! in_array('bunny', $providers, true)) {
            return;
        }

        foreach (BunnyStreamProvider::REQUIRED as $key => $env) {
            $value = config("video.providers.bunny.{$key}");

            throw_if(
                ! is_string($value) || trim($value) === '',
                RuntimeException::class,
                "{$env} phải được đặt khi nhà cung cấp video bunny đang bật (US-021, BR11)."
            );
        }

        $webhookToken = config('video.providers.bunny.webhook_token');

        throw_if(
            ! is_string($webhookToken) || mb_strlen($webhookToken) < self::VIDEOLAB_KEY_MIN_LENGTH,
            RuntimeException::class,
            'BUNNY_WEBHOOK_TOKEN phải đặt và dài tối thiểu '.self::VIDEOLAB_KEY_MIN_LENGTH.' ký tự khi bunny bật (openssl rand -hex 32; S1 review T37).'
        );

        foreach (['api_base' => 'BUNNY_API_BASE', 'tus_endpoint' => 'BUNNY_TUS_ENDPOINT'] as $key => $env) {
            throw_unless(
                str_starts_with(mb_strtolower(trim((string) config("video.providers.bunny.{$key}"))), 'https://'),
                RuntimeException::class,
                "{$env} phải dùng https (US-021)."
            );
        }

        $raw = trim((string) config('video.providers.bunny.cdn_host'));

        throw_if(
            preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) === 1 && ! str_starts_with(mb_strtolower($raw), 'https://'),
            RuntimeException::class,
            'BUNNY_CDN_HOST phải dùng https (US-021, BR11).'
        );

        $host = $this->normalizeHost((string) (str_starts_with(mb_strtolower($raw), 'https://') ? parse_url($raw, PHP_URL_HOST) : $raw));

        throw_if(
            $host === '' || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1 || filter_var($host, FILTER_VALIDATE_IP) !== false,
            RuntimeException::class,
            'BUNNY_CDN_HOST phải là tên miền (không IP, không đường dẫn, không cổng) (US-021, BR11).'
        );

        $others = [
            'APP_URL' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'FRONTEND_URL' => (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST),
            'ADMIN_URL' => (string) parse_url((string) config('app.admin_url'), PHP_URL_HOST),
            'APP_API_HOST' => (string) config('app.api_host'),
            'APP_ADMIN_API_HOST' => (string) config('app.admin_api_host'),
        ];

        foreach ($others as $name => $other) {
            throw_if(
                $other !== '' && $host === $this->normalizeHost($other),
                RuntimeException::class,
                "BUNNY_CDN_HOST không được trùng host của {$name} (US-021, BR11)."
            );
        }

        $cookieDomain = $this->normalizeHost((string) config('session.domain'));

        throw_if(
            $cookieDomain !== '' && $cookieDomain !== 'null' && ($host === $cookieDomain || str_ends_with($host, '.'.$cookieDomain)),
            RuntimeException::class,
            'SESSION_DOMAIN đang phủ cả BUNNY_CDN_HOST: cookie phiên sẽ gửi sang CDN video (US-021).'
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
