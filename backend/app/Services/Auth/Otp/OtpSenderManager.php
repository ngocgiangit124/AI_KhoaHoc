<?php

namespace App\Services\Auth\Otp;

use RuntimeException;

/**
 * Chọn `OtpSender` theo kênh (US-001, api-contract §2.2). `LogSmsOtpSender`
 * (kênh `sms`) CHỈ được resolve ở local/testing — phòng thủ 2 lớp cùng
 * `ProductionConfigGuard::guardOtpChannels()` (chặn ngay lúc boot nếu
 * `AUTH_OTP_CHANNELS` chứa `sms` ở production).
 */
class OtpSenderManager
{
    public function __construct(private readonly MailOtpSender $mailSender) {}

    public function forChannel(string $channel): OtpSender
    {
        return match ($channel) {
            'email' => $this->mailSender,
            'sms' => $this->smsSender(),
            default => throw new RuntimeException("Kênh OTP không hợp lệ: '{$channel}'."),
        };
    }

    private function smsSender(): OtpSender
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('LogSmsOtpSender chỉ được dùng ở local/testing (S9, S11).');
        }

        return app(LogSmsOtpSender::class);
    }
}
