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

    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param  array<string, mixed>  $context  Dữ liệu bổ sung cho response (không chứa PII/secret).
     * @param  array<string, string>  $headers  T04 security review L3 — header HTTP bổ sung (vd
     *                                          `Retry-After` cho `TOO_MANY_ATTEMPTS` ném từ tầng
     *                                          Service, không đi qua middleware `throttle:`). KHÔNG
     *                                          trộn vào `$context` (tránh lẫn vào `errors` của body —
     *                                          xem `ApiExceptionRenderer`).
     */
    public function __construct(
        string $code,
        string $message,
        int $status = 422,
        array $context = [],
        ?Throwable $previous = null,
        array $headers = [],
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $code;
        $this->status = $status;
        $this->context = $context;
        $this->headers = $headers;
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

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
