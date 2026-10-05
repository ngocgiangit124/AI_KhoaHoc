<?php

namespace App\Services\Auth\Otp;

/**
 * Nhà cung cấp SMS. Production MVP CHƯA có nhà cung cấp thật nên interface này
 * không được bind ở production (S9, S11); kênh `sms` bị chặn từ `auth.otp.channels`.
 */
interface SmsOtpSender
{
    public function send(string $phone, string $code): void;
}
