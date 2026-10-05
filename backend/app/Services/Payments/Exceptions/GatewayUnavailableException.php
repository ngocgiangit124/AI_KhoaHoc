<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Cổng timeout/lỗi mạng/từ chối. T18 map sang 502 PAYMENT_GATEWAY_UNAVAILABLE. Thông điệp không chứa secret.
 */
class GatewayUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Cổng thanh toán không khả dụng: '.$reason, 0, $previous);
    }
}
