<?php

namespace App\Services\Payments\Data;

use DateTimeInterface;

final readonly class PaymentRequest
{
    /**
     * @param  int  $amount  số nguyên VNĐ
     * @param  string  $description  KHÔNG chứa PII; chỉ "Thanh toan don hang {code}" (ADR-001 §2)
     */
    public function __construct(
        public string $gatewayOrderId,
        public string $requestId,
        public int $amount,
        public string $description,
        public string $returnUrl,
        public string $notifyUrl,
        public DateTimeInterface $expiresAt,
    ) {}
}
