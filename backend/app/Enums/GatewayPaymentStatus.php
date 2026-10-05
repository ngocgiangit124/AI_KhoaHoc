<?php

namespace App\Enums;

/**
 * Trạng thái thanh toán đã chuẩn hoá (ADR-001 §1) — nghiệp vụ chỉ biết 3 giá trị này;
 * bảng mã riêng của từng cổng nằm trong adapter.
 */
enum GatewayPaymentStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
}
