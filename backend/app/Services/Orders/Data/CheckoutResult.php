<?php

namespace App\Services\Orders\Data;

use App\Models\Order;
use App\Models\PaymentAttempt;

/**
 * Kết quả POST /checkout.
 *
 * - `attempt` có `pay_url`: HS chuyển sang cổng thanh toán.
 * - `attempt` null và `order.status = paid`: đơn 0đ đã hoàn tất, không qua cổng.
 * - `attempt` null và đơn còn pending (`linkExpired`): link cũ hết hạn nhưng chưa được cổng xác nhận → HS gọi
 *   POST /orders/{code}/pay (T20) để đối soát rồi tạo link mới.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public ?PaymentAttempt $attempt,
        public bool $reused,
        public bool $linkExpired = false,
    ) {}
}
