<?php

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Cache::flush());

test('T27-3: loi gui OTP o forgot duoc ghi log muc error (khong PII/ma) nhung response van 202 nhu binh thuong', function () {
    vvFakeOtp();
    app()->instance(OtpSender::class, new class implements OtpSender
    {
        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            $GLOBALS['vvLeakedOtp'] = $code;
            throw new RuntimeException("SMTP loi gui toi {$destination} ma {$code}");
        }
    });
    vvPwStudent();
    $logs = [];
    $GLOBALS['vvLeakedOtp'] = null;
    Log::listen(function ($e) use (&$logs): void {
        $logs[$e->message] = $e;
    });

    vvForgot()->assertStatus(202)->assertJsonPath('message', 'Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.');

    $leakedCode = $GLOBALS['vvLeakedOtp'];
    expect($logs)->toHaveKeys(['Gửi OTP thất bại.', 'password_reset.send_failed']);
    expect($logs['Gửi OTP thất bại.']->level)->toBe('error')
        ->and($logs['Gửi OTP thất bại.']->context['channel'])->toBe('email')
        ->and(json_encode($logs['Gửi OTP thất bại.']->context))->not->toContain('hs@example.com');
    // Mã OTP thật (đã băm trong DB, lấy từ exception của sender giả) không được xuất hiện ở bất kỳ log nào.
    expect($leakedCode)->not->toBeNull();
    foreach ($logs as $entry) {
        expect(json_encode([$entry->message, $entry->context]))->not->toContain((string) $leakedCode);
    }
    expect($logs['password_reset.send_failed']->level)->toBe('error')
        ->and($logs['password_reset.send_failed']->context)->toBe(['exception' => DomainException::class, 'code' => 'OTP_DELIVERY_FAILED']);
});
