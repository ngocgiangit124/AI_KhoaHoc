<?php

namespace App\Enums;

/**
 * @deprecated ADR-006: không còn phụ huynh đồng ý. Cột `users.parent_consent_status` luôn ghi `not_required`
 *             (giữ để tương thích API v1); xoá ở backlog T29-1.
 */
enum ParentConsentStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Granted = 'granted';
    case Revoked = 'revoked';
}
