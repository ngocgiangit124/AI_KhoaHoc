<?php

namespace App\Services\Payments\Exceptions;

use App\Exceptions\DomainException;

/**
 * Số tiền ngoài hạn mức cổng (MoMo tối thiểu 1.000đ) — ADR-001 §8: 422 AMOUNT_BELOW_GATEWAY_MIN.
 */
class AmountOutOfRangeException extends DomainException
{
    public static function below(int $min): self
    {
        return new self('AMOUNT_BELOW_GATEWAY_MIN', 'Số tiền thanh toán thấp hơn mức tối thiểu của cổng.', 422, ['min' => $min]);
    }

    public static function above(int $max): self
    {
        return new self('AMOUNT_ABOVE_GATEWAY_MAX', 'Số tiền thanh toán vượt mức tối đa của cổng.', 422, ['max' => $max]);
    }
}
