<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
