<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 429 `MANUAL_ORDER_LIMIT` (api-contract §1.7/§2.3.1): quá số đơn `manual` mới trong ngày lịch giờ Việt Nam. Là DomainException
 * (renderer lấy code/context) kèm `Retry-After` — renderer chỉ chép header của HttpExceptionInterface.
 */
class ManualOrderLimitException extends DomainException implements HttpExceptionInterface
{
    public function __construct(int $limit, string $resetsAtIso, private readonly int $retryAfterSeconds)
    {
        parent::__construct(
            'MANUAL_ORDER_LIMIT',
            'Bạn đã đặt quá nhiều đơn hôm nay, vui lòng liên hệ Quản trị viên.',
            429,
            ['limit' => $limit, 'resets_at' => $resetsAtIso],
        );
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) max(1, $this->retryAfterSeconds)];
    }
}
