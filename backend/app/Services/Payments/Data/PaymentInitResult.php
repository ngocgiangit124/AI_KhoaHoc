<?php

namespace App\Services\Payments\Data;

use DateTimeInterface;

final readonly class PaymentInitResult
{
    /**
     * @param  array<string, mixed>  $rawResponse  đã bỏ `signature`; lưu vào payment_attempts.create_response
     */
    public function __construct(
        public string $payUrl,
        public DateTimeInterface $expiresAt,
        public array $rawResponse,
    ) {}
}
