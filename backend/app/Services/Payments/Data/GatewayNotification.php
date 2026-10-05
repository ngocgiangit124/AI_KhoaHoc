<?php

namespace App\Services\Payments\Data;

use App\Enums\GatewayPaymentStatus;

/**
 * Kết quả chuẩn hoá của IPN hoặc query. Chỉ được tạo SAU khi chữ ký đã hợp lệ.
 */
final readonly class GatewayNotification
{
    /**
     * @param  int  $amount  số nguyên VNĐ (đã parse nghiêm ngặt)
     * @param  bool  $expired  cổng báo link hết hạn/giao dịch không tồn tại (status = Failed) → T20 đặt attempt `expired`
     * @param  array<string, mixed>  $raw  chỉ các trường đã biết, đã bỏ `signature` (lưu payment_webhook_events.payload)
     */
    public function __construct(
        public string $gatewayOrderId,
        public string $requestId,
        public string $transactionId,
        public int $amount,
        public GatewayPaymentStatus $status,
        public int $resultCode,
        public string $message,
        public array $raw,
        public bool $expired = false,
    ) {}
}
