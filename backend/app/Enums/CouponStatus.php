<?php

namespace App\Enums;

/**
 * `coupons.status` (US-013, data-model §3.5). "Hết hạn"/"Hết lượt" là trạng
 * thái HIỂN THỊ suy diễn từ `valid_until`/`used_count` (đặc tả UX US-013 §2.1),
 * không lưu ở cột này — chỉ `active`/`inactive` là dữ liệu thật.
 */
enum CouponStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
