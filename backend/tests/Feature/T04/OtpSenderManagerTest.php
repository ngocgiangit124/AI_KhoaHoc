<?php

use App\Services\Auth\Otp\LogSmsOtpSender;
use App\Services\Auth\Otp\MailOtpSender;
use App\Services\Auth\Otp\OtpSenderManager;
use Illuminate\Support\Facades\Log;

test('forChannel(email) tra ve MailOtpSender', function () {
    $manager = app(OtpSenderManager::class);

    expect($manager->forChannel('email'))->toBeInstanceOf(MailOtpSender::class);
});

test('forChannel(sms) o local/testing tra ve LogSmsOtpSender', function () {
    $manager = app(OtpSenderManager::class);

    expect($manager->forChannel('sms'))->toBeInstanceOf(LogSmsOtpSender::class);
});

test('forChannel(sms) ngoai local/testing nem loi (S9, S11)', function () {
    app()->detectEnvironment(fn () => 'production');
    $manager = app(OtpSenderManager::class);

    expect(fn () => $manager->forChannel('sms'))->toThrow(RuntimeException::class);
});

test('forChannel voi kenh la nem loi', function () {
    $manager = app(OtpSenderManager::class);

    expect(fn () => $manager->forChannel('zalo'))->toThrow(RuntimeException::class);
});

/**
 * T04 — "LogSmsOtpSender chỉ bind ở local/testing và ghi ***": mã OTP KHÔNG
 * BAO GIỜ được ghi ra log (S21), kể cả ở kênh mô phỏng cho local/testing.
 */
test('LogSmsOtpSender ghi *** thay vi ma that (S21)', function () {
    Log::spy();

    (new LogSmsOtpSender)->send('0912345678', '123456');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $context['destination'] === '0912345678'
                && $context['code'] === '***'
                && ! str_contains($message, '123456')
                && ! in_array('123456', $context, true);
        });
});
