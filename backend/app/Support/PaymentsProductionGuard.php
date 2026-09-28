<?php

namespace App\Support;

use RuntimeException;

/**
 * Boot guard kiểu ALLOWLIST cho cấu hình thanh toán (ADR-001 §1, S4).
 *
 * Tách khỏi `AppServiceProvider` để test được bằng tham số thuần (không cần
 * boot cả ứng dụng ở môi trường "production" giả). Nguyên tắc allowlist:
 * mọi `enabled_gateways` KHÔNG có trong `KNOWN_GATEWAYS` đều bị chặn ở
 * production — kể cả tên lạ/gõ sai, không riêng gì `'fake'`. `match` không
 * có nhánh `default`: thêm cổng mới vào `KNOWN_GATEWAYS` mà quên viết hàm
 * kiểm tương ứng sẽ ném `\UnhandledMatchError` khi boot thay vì âm thầm cho
 * qua (bài học M3 — không có nhánh rơi về an toàn giả).
 */
final class PaymentsProductionGuard
{
    /** @var list<string> */
    private const KNOWN_GATEWAYS = ['momo'];

    /**
     * @param  list<string>  $enabledGateways
     * @param  array<string, array<string, mixed>>  $gatewaysConfig
     */
    public static function assertSafeForProduction(bool $isProduction, array $enabledGateways, array $gatewaysConfig): void
    {
        if (! $isProduction) {
            return;
        }

        if ($enabledGateways === []) {
            throw new RuntimeException('Không có cổng thanh toán nào được bật (S4).');
        }

        foreach ($enabledGateways as $gateway) {
            if (! in_array($gateway, self::KNOWN_GATEWAYS, true)) {
                throw new RuntimeException(sprintf(
                    'Cổng thanh toán "%s" không nằm trong allowlist production (S4).',
                    $gateway,
                ));
            }

            match ($gateway) {
                'momo' => self::assertMomoSafe($gatewaysConfig['momo'] ?? []),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $momo
     */
    private static function assertMomoSafe(array $momo): void
    {
        $endpoint = (string) ($momo['endpoint'] ?? '');

        if (! str_starts_with($endpoint, 'https://payment.momo.vn')) {
            throw new RuntimeException('MoMo endpoint không phải production (S4).');
        }

        foreach (['partner_code', 'access_key', 'secret_key'] as $key) {
            if (trim((string) ($momo[$key] ?? '')) === '') {
                throw new RuntimeException(sprintf(
                    'Thiếu cấu hình MoMo "%s" ở production (S4).',
                    $key,
                ));
            }
        }
    }
}
