<?php

namespace App\Services\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Models\User;

/**
 * Cổng gửi OTP ra ngoài (ADR-004: interface vì có thể đổi nhà cung cấp SMS).
 * Mã rõ chỉ đi qua đây một lần — KHÔNG được ghi log/audit.
 */
interface OtpSender
{
    public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void;
}
