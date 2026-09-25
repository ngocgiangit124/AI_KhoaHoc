<?php

namespace App\Enums;

enum ConsentType: string
{
    case PrivacyPolicy = 'privacy_policy';
    case Terms = 'terms';
    case ParentConsent = 'parent_consent';
    case Marketing = 'marketing';
}
