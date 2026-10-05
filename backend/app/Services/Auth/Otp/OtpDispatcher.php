<?php

namespace App\Services\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Định tuyến theo kênh: email → OtpMail (queue, mã hoá payload — S21);
 * sms → SmsOtpSender (chỉ có ở local/testing).
 */
class OtpDispatcher implements OtpSender
{
    public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
    {
        if ($channel === 'email') {
            Mail::to($destination)->send(new OtpMail($user->name, $code, $purpose, (int) config('auth.otp.ttl_minutes')));

            return;
        }

        if ($channel === 'sms') {
            try {
                $sms = app(SmsOtpSender::class);
            } catch (BindingResolutionException) {
                throw new RuntimeException('Kênh SMS chưa được cấu hình ở môi trường này.');
            }

            $sms->send($destination, $code);

            return;
        }

        throw new RuntimeException('Kênh OTP không hợp lệ.');
    }
}
