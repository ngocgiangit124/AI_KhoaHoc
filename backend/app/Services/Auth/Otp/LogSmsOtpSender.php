<?php

namespace App\Services\Auth\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Chỉ bind ở local/testing (AppServiceProvider). KHÔNG BAO GIỜ ghi mã OTP —
 * chỉ ghi `***` và SĐT đã che (S9).
 */
class LogSmsOtpSender implements SmsOtpSender
{
    public function send(string $phone, string $code): void
    {
        Log::info('SMS OTP (giả lập) đã được yêu cầu gửi.', [
            'to' => str_repeat('*', max(0, strlen($phone) - 3)).substr($phone, -3),
            'code' => '***',
        ]);
    }
}
