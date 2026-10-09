<?php

namespace App\Services\Orders;

use App\Services\Payments\PaymentGatewayManager;

/**
 * Danh sách phương thức thanh toán CÓ TIỀN đang bật (ADR-007 §2). Nơi DUY NHẤT quyết định "mua khóa có phí được không":
 *
 *  - `manual` ("Liên hệ Quản trị viên") có mặt khi `features.manual_payment`;
 *  - các cổng (`momo`...) có mặt khi `features.paid_checkout` và nằm trong `payments.enabled_gateways`.
 *
 * `manual` đứng trước (mặc định khi request không chọn). Hai cờ độc lập: tắt MoMo chỉ ẩn nó ở đây, không đụng code cổng.
 * Riêng `ProductionConfigGuard` và `/orders/{code}/pay` (T20) vẫn đọc cờ MoMo trực tiếp.
 */
class PaymentMethods
{
    public const MANUAL = 'manual';

    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /** @return list<string> */
    public function available(): array
    {
        $methods = [];

        if (config('features.manual_payment')) {
            $methods[] = self::MANUAL;
        }

        if (config('features.paid_checkout')) {
            foreach ($this->gateways->enabled() as $gateway) {
                if ($gateway !== '' && $gateway !== self::MANUAL && ! in_array($gateway, $methods, true)) {
                    $methods[] = $gateway;
                }
            }
        }

        return $methods;
    }

    public function isAvailable(string $method): bool
    {
        return in_array(mb_strtolower($method), $this->available(), true);
    }

    /** Phương thức mặc định khi request không chọn; null khi không có phương thức nào. */
    public function default(): ?string
    {
        return $this->available()[0] ?? null;
    }

    public function hasManual(): bool
    {
        return in_array(self::MANUAL, $this->available(), true);
    }

    /**
     * Nhãn cho preview (api-contract §2.3.1). Cổng thanh toán chưa có mô tả riêng nên dùng tên mã.
     *
     * @return list<array{code: string, label: string, description: string}>
     */
    public function describe(): array
    {
        return array_map(fn (string $code): array => $code === self::MANUAL
            ? ['code' => $code, 'label' => (string) config('orders.manual.label'), 'description' => (string) config('orders.manual.description')]
            : ['code' => $code, 'label' => mb_strtoupper($code), 'description' => 'Thanh toán trực tuyến qua '.mb_strtoupper($code)],
            $this->available());
    }

    /**
     * Khối `manual_payment` của `/config/public`; null khi `manual` không bật.
     *
     * @return array<string, mixed>|null
     */
    public function manualPublicConfig(): ?array
    {
        if (! $this->hasManual()) {
            return null;
        }

        $contact = (array) config('orders.manual.contact', []);

        return [
            'label' => (string) config('orders.manual.label'),
            'description' => (string) config('orders.manual.description'),
            'pending_ttl_hours' => (int) config('orders.manual.pending_ttl_hours'),
            'contact' => [
                'phone' => $this->nullable($contact['phone'] ?? null),
                'zalo_url' => $this->nullable($contact['zalo_url'] ?? null),
                'email' => $this->nullable($contact['email'] ?? null),
                'hours' => $this->nullable($contact['hours'] ?? null),
            ],
        ];
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
