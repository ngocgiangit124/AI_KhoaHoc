<?php

namespace App\Services\Payments\Data;

use Carbon\CarbonInterface;

/**
 * Tham số tạo giao dịch phía cổng (ADR-001 §1).
 *
 * `amount` luôn là số nguyên VNĐ. `description` (= `orderInfo` của MoMo)
 * KHÔNG được chứa PII (S12.6) — người gọi (CheckoutService, T18) chịu
 * trách nhiệm chỉ truyền mã đơn, ví dụ "Thanh toan don hang {code}".
 */
final class PaymentRequest
{
    public function __construct(
        public readonly string $gatewayOrderId,
        public readonly string $requestId,
        public readonly int $amount,
        public readonly string $description,
        public readonly string $returnUrl,
        public readonly string $notifyUrl,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}
}
