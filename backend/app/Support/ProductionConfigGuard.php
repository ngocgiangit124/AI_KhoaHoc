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
    /** @var list<string> */
    private const KNOWN_GATEWAYS = ['momo'];

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
     * S4/T17 — ALLOWLIST tường minh cho cổng thanh toán: bất kỳ tên nào KHÔNG
     * nằm trong `KNOWN_GATEWAYS` đều bị chặn ở production (không riêng gì
     * `fake` — kể cả tên lạ/gõ sai chính tả), không phân biệt hoa/thường.
     * `match` không có nhánh `default`: thêm cổng vào `KNOWN_GATEWAYS` mà quên
     * viết hàm `guard*` tương ứng sẽ ném `\UnhandledMatchError` lúc boot thay
     * vì âm thầm cho qua (bài học M3 — không có nhánh rơi về an toàn giả).
     * Endpoint MoMo phải khớp ĐÚNG allowlist scheme+host (không dùng blocklist
     * kiểu `str_contains('test-payment')`, dễ bỏ sót biến thể khác); phải có
     * đủ `partner_code`/`access_key`/`secret_key`.
     */
    private function guardPayments(): void
    {
        $gateways = array_map(
            static fn ($gateway) => mb_strtolower((string) $gateway),
            (array) config('payments.enabled_gateways', [])
        );

        throw_if(
            $gateways === [],
            RuntimeException::class,
            'Không có cổng thanh toán nào được bật ở production (S4).'
        );

        foreach ($gateways as $gateway) {
            throw_unless(
                in_array($gateway, self::KNOWN_GATEWAYS, true),
                RuntimeException::class,
                "Cổng thanh toán '{$gateway}' không nằm trong allowlist production (S4)."
            );

            match ($gateway) {
                'momo' => $this->guardMomo(),
            };
        }
    }

    private function guardMomo(): void
    {
        $endpoint = (string) config('payments.gateways.momo.endpoint');
        $parts = parse_url($endpoint);
        $isAllowedMomoEndpoint = ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'payment.momo.vn';

        throw_if(
            ! $isAllowedMomoEndpoint,
            RuntimeException::class,
            'MOMO_ENDPOINT phải đúng https://payment.momo.vn ở production (S4, M4).'
        );

        foreach (['partner_code', 'access_key', 'secret_key'] as $key) {
            throw_if(
                trim((string) config("payments.gateways.momo.{$key}")) === '',
                RuntimeException::class,
                "Thiếu cấu hình MOMO_{$key} ở production (S4, T17)."
            );
        }

        // R4 (review vòng 2 docs/reviews/review-T17.md) — `pay_url_hosts`
        // (M1) pin host của `payUrl` MoMo trả về, nhưng giá trị MẶC ĐỊNH
        // trong `.env.example`/`config/payments.php` gộp CẢ host sandbox
        // (`test-payment.momo.vn`) để dùng chung 1 default cho mọi môi
        // trường. Nếu vận hành quên override riêng cho production, ứng dụng
        // vẫn boot và `MoMoGateway::isTrustedPayUrl()` sẽ chấp nhận cả
        // `payUrl` trỏ tới host sandbox — thu hẹp nhưng không đóng lỗ hổng
        // mà M1 định chặn. Bắt buộc ĐÚNG CHỈ 1 phần tử `payment.momo.vn`
        // (không rỗng, không kèm host nào khác kể cả sandbox).
        $payUrlHosts = (array) config('payments.gateways.momo.pay_url_hosts', []);

        throw_if(
            $payUrlHosts !== ['payment.momo.vn'],
            RuntimeException::class,
            "MOMO_PAY_URL_HOSTS ở production phải đúng CHỈ 'payment.momo.vn' (không được kèm host sandbox), hiện là: ".implode(',', $payUrlHosts)
        );
    }
}
