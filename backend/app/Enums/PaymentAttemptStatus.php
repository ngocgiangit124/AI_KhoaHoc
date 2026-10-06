<?php

namespace App\Enums;

/** ADR-001 §3: `failed`/`expired` chỉ khi cổng xác nhận; `error` = tạo giao dịch thất bại (chưa từng có pay_url). */
enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Error = 'error';
}
