<?php

namespace App\Services\Payments\Data;

/**
 * Tham chiếu tối thiểu tới một `payment_attempts` (bảng thuộc T18, chưa tồn
 * tại ở T17) cần cho `PaymentGateway::queryStatus()`. Tách khỏi Eloquent
 * model để lớp cổng thanh toán không phụ thuộc ngược vào T18 — khi T18 tạo
 * model `PaymentAttempt`, chỉ cần map sang DTO này (hoặc cho model implement
 * một phương thức `toGatewayReference()` trả về đối tượng này).
 */
final class PaymentAttemptReference
{
    public function __construct(
        public readonly string $gatewayOrderId,
        public readonly string $requestId,
        public readonly int $amount,
    ) {}
}
