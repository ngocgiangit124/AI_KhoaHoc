<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case VerifyAccount = 'verify_account';
    case ResetPassword = 'reset_password';
    case StaffLoginMfa = 'staff_login_mfa';
    // T34 (ADR-006): xác nhận xoá (ẩn danh hoá) tài khoản học sinh.
    case DeleteAccount = 'delete_account';
}
