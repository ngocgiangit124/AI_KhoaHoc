<?php

namespace App\Services\Payments\Exceptions;

use App\Exceptions\DomainException;
use Throwable;

/**
 * Lỗi mạng/timeout/5xx hoặc phản hồi không hợp lệ khi tạo/tra cứu giao dịch
 * (ADR-001 §1). Ánh xạ sang 502 `PAYMENT_GATEWAY_UNAVAILABLE` (api-contract §1.7).
 */
final class GatewayUnavailableException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Không thể kết nối tới cổng thanh toán. Vui lòng thử lại sau.',
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct('PAYMENT_GATEWAY_UNAVAILABLE', $message, 502, $context, $previous);
    }
}
