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
        /**
         * R3 (review docs/reviews/review-T17.md) — `requestId` của LẦN TẠO
         * giao dịch ban đầu (`payment_attempts.request_id`), KHÔNG phải
         * `requestId` dùng cho request `query`: `MoMoGateway::queryStatus()`
         * luôn tự sinh một UUID mới cho mỗi lần gọi (đúng ADR-001 §2 —
         * "requestId (UUID mới)") nên field này không được đọc bên trong
         * adapter. Giữ lại ở DTO để tầng gọi (T18/T19 — `PaymentWebhookService`)
         * dùng đối chiếu/log (ví dụ so với `requestId` mà IPN trả về), không
         * phải dead code.
         */
        public readonly string $requestId,
        public readonly int $amount,
    ) {}
}
