<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 429 `DATA_EXPORT_LIMIT` (api-contract §1.7): đã tải đủ số lần trong ngày lịch giờ Việt Nam. Là DomainException
 * (renderer lấy code/context) kèm `Retry-After` — renderer chỉ chép header của HttpExceptionInterface.
 */
class DataExportLimitException extends DomainException implements HttpExceptionInterface
{
    public function __construct(
        int $limit,
        string $resetsAtIso,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            'DATA_EXPORT_LIMIT',
            "Bạn đã tải dữ liệu {$limit} lần trong hôm nay. Vui lòng thử lại sau 00:00 ngày mai.",
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
