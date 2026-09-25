<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case VerifyAccount = 'verify_account';
    case ResetPassword = 'reset_password';
    case StaffLoginMfa = 'staff_login_mfa';
    case ParentConsent = 'parent_consent';
}
