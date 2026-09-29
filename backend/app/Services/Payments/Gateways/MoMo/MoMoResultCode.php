<?php

namespace App\Services\Payments\Gateways\MoMo;

use App\Services\Payments\Enums\PaymentStatus;

/**
 * Bảng mã kết quả MoMo → trạng thái chuẩn hoá (ADR-001 §2, S12.1).
 * CHỈ `0` là `Succeeded`. `9000` (authorized/chờ capture), `1000`, `7000`,
 * `7002` (đang xử lý) → `Pending`, KHÔNG BAO GIỜ `Succeeded`. Mọi mã khác
 * → `Failed`.
 *
 * Cần Dev đối chiếu lại với tài liệu MoMo hiện hành khi có quyền truy cập
 * sandbox thật (mạng bị chặn ở môi trường này — xem báo cáo T17).
 */
final class MoMoResultCode
{
    /** @var list<int> */
    private const PENDING_CODES = [1000, 7000, 7002, 9000];

    public static function toStatus(string $resultCode): PaymentStatus
    {
        if ($resultCode === '0') {
            return PaymentStatus::Succeeded;
        }

        if (ctype_digit($resultCode) && in_array((int) $resultCode, self::PENDING_CODES, true)) {
            return PaymentStatus::Pending;
        }

        return PaymentStatus::Failed;
    }
}
