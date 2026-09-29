<?php

namespace App\Services\Payments\Data;

use App\Services\Payments\Enums\PaymentStatus;

/**
 * IPN/kết quả query đã được xác thực chữ ký và chuẩn hoá (ADR-001 §1).
 * `amount` đã qua kiểm tra nghiêm ngặt (số nguyên, không nhận chuỗi thập
 * phân/khoa học/âm — S12.3). `raw` đã loại bỏ `signature`.
 */
final class GatewayNotification
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $gatewayOrderId,
        public readonly string $requestId,
        public readonly ?string $transactionId,
        public readonly int $amount,
        public readonly PaymentStatus $status,
        public readonly string $resultCode,
        public readonly string $message,
        public readonly array $raw,
    ) {}
}
