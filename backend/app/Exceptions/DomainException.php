<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Lỗi nghiệp vụ với mã máy đọc được (api-contract §1.7).
 *
 * Ví dụ: throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422);
 */
class DomainException extends RuntimeException
{
    private readonly string $errorCode;

    private readonly int $status;

    /** @var array<string, mixed> */
    private readonly array $context;

    /**
     * @param  array<string, mixed>  $context  Dữ liệu bổ sung cho response (không chứa PII/secret).
     */
    public function __construct(
        string $code,
        string $message,
        int $status = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $code;
        $this->status = $status;
        $this->context = $context;
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
