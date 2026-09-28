<?php

namespace App\Enums;

/**
 * `enrollments.source` (data-model §3.3). `admin_grant` dành cho tương lai
 * (chưa dùng ở MVP — data-model chỉ liệt kê 2 giá trị hiện tại).
 */
enum EnrollmentSource: string
{
    case Purchase = 'purchase';
    case FreeApproval = 'free_approval';
}
