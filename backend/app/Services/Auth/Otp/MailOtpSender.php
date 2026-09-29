<?php

namespace App\Services\Auth\Otp;

use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;

/**
 * Kênh `email` — kênh DUY NHẤT được bật ở production MVP (S9, S11 — chưa có
 * nhà cung cấp SMS thật).
 */
class MailOtpSender implements OtpSender
{
    public function channel(): string
    {
        return 'email';
    }

    public function send(string $destination, string $code): void
    {
        Mail::to($destination)->queue(new OtpMail($code));
    }
}
