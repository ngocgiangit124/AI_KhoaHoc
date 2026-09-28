<?php

namespace App\Services\Payments\Enums;

/**
 * Trạng thái chuẩn hoá dùng chung cho mọi cổng thanh toán (ADR-001 §1).
 * Nghiệp vụ (PaymentWebhookService — T19) chỉ biết 3 giá trị này; bảng mã
 * kết quả riêng của từng cổng (vd MoMo `resultCode`) nằm trong adapter.
 */
enum PaymentStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
}
