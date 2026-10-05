<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/**
 * Chữ ký sai/thiếu hoặc payload không hợp lệ. `reason` là mã ngắn (không chứa chữ ký/secret).
 * IPN → 400 + log `rejected_signature`; query → coi như lỗi, không hành động.
 */
class InvalidSignatureException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Chữ ký/payload cổng thanh toán không hợp lệ: '.$reason);
    }
}
