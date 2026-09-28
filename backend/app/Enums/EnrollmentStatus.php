<?php

namespace App\Enums;

/**
 * `enrollments.status` (US-012, data-model §3.3). Chỉ đổi qua `EnrollmentService`
 * (T14) — không bao giờ nằm trong `$fillable` của Model `Enrollment` (S17).
 */
enum EnrollmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
