<?php

namespace App\Services\Payments\Exceptions;

use App\Exceptions\DomainException;
use Throwable;

/**
 * Chữ ký sai/thiếu trường bắt buộc ở IPN hoặc phản hồi query (ADR-001 §1, S12).
 * Không có mã lỗi chuẩn hoá trong api-contract §1.7 cho webhook (endpoint
 * webhook không map lỗi ra cho người dùng cuối) — controller IPN (T19) bắt
 * riêng exception này để trả 400 và ghi log `rejected_signature`, KHÔNG lộ
 * qua `ApiExceptionRenderer` mặc định cho client là cổng thanh toán.
 */
final class InvalidSignatureException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Chữ ký không hợp lệ hoặc thiếu trường bắt buộc.',
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct('INVALID_GATEWAY_SIGNATURE', $message, 400, $context, $previous);
    }
}
