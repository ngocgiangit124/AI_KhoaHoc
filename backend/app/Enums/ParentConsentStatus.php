<?php

namespace App\Enums;

enum ParentConsentStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Granted = 'granted';
    case Revoked = 'revoked';
}
