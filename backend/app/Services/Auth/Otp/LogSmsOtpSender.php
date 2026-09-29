<?php

namespace App\Services\Auth\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Kênh `sms` MÔ PHỎNG — CHỈ được resolve ở local/testing
 * (`OtpSenderManager::forChannel()`), production chưa có nhà cung cấp SMS
 * thật (S9, S11) và bị `ProductionConfigGuard` cấm cấu hình. KHÔNG BAO GIỜ ghi
 * mã OTP thật ra log (S21) — chỉ ghi `'***'`, đúng số điện thoại nhận (để dev
 * đối chiếu khi kiểm thủ công) nhưng không phải mã.
 */
class LogSmsOtpSender implements OtpSender
{
    public function channel(): string
    {
        return 'sms';
    }

    public function send(string $destination, string $code): void
    {
        Log::info('[OTP giả lập — chỉ local/testing] Gửi SMS OTP', [
            'destination' => $destination,
            'code' => '***',
        ]);
    }
}
