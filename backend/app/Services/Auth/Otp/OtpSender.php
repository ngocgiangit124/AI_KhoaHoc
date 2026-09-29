<?php

namespace App\Services\Auth\Otp;

/**
 * Gửi mã OTP qua 1 kênh cụ thể (US-001, api-contract §2.2). Tách interface để
 * dễ thay nhà cung cấp SMS thật sau này mà không đổi `OtpService`.
 */
interface OtpSender
{
    /**
     * Tên kênh (khớp giá trị trong `config('auth.otp.channels')`), vd 'email'/'sms'.
     */
    public function channel(): string;

    /**
     * @param  string  $destination  Email hoặc SĐT nhận mã.
     * @param  string  $code  Mã 6 số ở DẠNG RÕ — implementation KHÔNG BAO GIỜ
     *                        được ghi giá trị này ra log (S21).
     */
    public function send(string $destination, string $code): void;
}
