<?php

namespace App\Services\Payments\Data;

use Carbon\CarbonInterface;

/**
 * Kết quả tạo giao dịch thành công (ADR-001 §1).
 *
 * `rawResponse` đã loại bỏ `signature`/secret trước khi lưu (an toàn cho log
 * và cột `payment_attempts.create_response`, T18).
 */
final class PaymentInitResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $payUrl,
        public readonly ?CarbonInterface $expiresAt,
        public readonly array $rawResponse,
    ) {}
}
