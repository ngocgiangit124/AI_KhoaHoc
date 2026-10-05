<?php

namespace App\Services\Payments\Data;

/**
 * Đầu vào của queryStatus. Dùng DTO thay vì model `PaymentAttempt` (bảng/model thuộc T18) để adapter
 * không phụ thuộc Eloquent. LƯU Ý: adapter KHÔNG so `amount` của phản hồi với `$amount` ở đây — T19/T20
 * (`PaymentWebhookService::apply`) phải tự so `notification->amount === attempt->amount` (lệch → needs_review); T18/T20 dựng từ attempt: `new PaymentStatusQuery($a->gateway_order_id, $a->amount)`.
 */
final readonly class PaymentStatusQuery
{
    public function __construct(
        public string $gatewayOrderId,
        public int $amount,
    ) {}
}
