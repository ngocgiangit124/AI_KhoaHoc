<?php

namespace App\Enums;

/**
 * Trạng thái hiệu lực suy ra (không lưu cột) để hiển thị/lọc ở trang quản trị (US-013 AC4, AC6).
 * Thứ tự ưu tiên khi nhiều điều kiện cùng đúng: inactive > expired > exhausted > upcoming > active.
 */
enum CouponState: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Upcoming = 'upcoming';
}
