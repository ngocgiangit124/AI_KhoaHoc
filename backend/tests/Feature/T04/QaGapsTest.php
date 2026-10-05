<?php

use App\Enums\OtpPurpose;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

/** QA T04 — các ca bổ sung ngoài bộ test của Dev. */
function vvQaLogFile(): string
{
    $logFile = tempnam(sys_get_temp_dir(), 'vv-qa-log-');
    config(['logging.channels.qa_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('qa_test');

    return $logFile;
}

test('BUG-1 S21: nhap ma (dung) sau khi het 5 luot -> log KHONG duoc chua ma nguoi dung gui (stack trace args)', function () {
    config(['auth.otp.max_verify_per_minute' => 100]);
    $logFile = vvQaLogFile();
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend();
    $right = $sender->lastCode();
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        vvOtpVerify($wrong)->assertStatus(422);
    }
    vvOtpVerify($right)->assertStatus(429);

    $log = (string) file_get_contents($logFile);
    @unlink($logFile);

    expect($log)->not->toContain("'{$right}'")
        ->and($log)->not->toContain("'{$wrong}'");
});

test('AC8 tai khoan da xac thuc: verify them lan nua khong doi trang thai, khong 500', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent();
    vvOtpSend();
    vvOtpVerify($sender->lastCode())->assertOk();

    vvOtpVerify('123456')->assertStatus(422);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('AC1 email OTP hien ten co dau va khong vo dau tieng Viet', function () {
    $mail = new OtpMail('Nguyễn Thị Ánh', '654321', OtpPurpose::VerifyAccount, 10);
    $mail->assertSeeInHtml('Nguyễn Thị Ánh');
    $mail->assertSeeInHtml('654321');
});

test('AC1 ten chua HTML bi escape trong email OTP (khong XSS qua mail)', function () {
    $mail = new OtpMail('<script>alert(1)</script>', '654321', OtpPurpose::VerifyAccount, 10);
    expect($mail->render())->not->toContain('<script>alert(1)</script>')->and($mail->render())->toContain('&lt;script&gt;');
});

test('AC8 ranh gioi het han: ma con 1 giay dung duoc, qua han 1 giay bi tu choi', function () {
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend();
    $code = $sender->lastCode();
    $otp = OtpCode::first();

    $this->travelTo($otp->expires_at->copy()->subSecond());
    vvOtpVerify($code)->assertOk();
    $this->travelBack();

    $user2 = User::factory()->create();
    vvActAsStudent($user2);
    vvOtpSend();
    $code2 = $sender->lastCode();
    $otp2 = OtpCode::query()->where('user_id', $user2->id)->first();
    $this->travelTo($otp2->expires_at->copy()->addSecond());
    vvOtpVerify($code2)->assertStatus(422);
    $this->travelBack();
    expect($user2->fresh()->email_verified_at)->toBeNull();
});

test('contact: email chua khoang trang/hoa thuong duoc chuan hoa; email co ky tu la bi 422', function () {
    vvFakeOtp();
    $user = vvOtpStudent();
    vvContactUpdate(['email' => '  Moi.Hoc.Sinh@Example.COM '])->assertOk();
    expect($user->fresh()->email)->toBe('moi.hoc.sinh@example.com');
    vvContactUpdate(['email' => 'a b@example.com'])->assertStatus(422);
});

test('contact: SDT sai dinh dang -> 422 field phone, khong doi gi', function () {
    vvFakeOtp();
    $user = vvOtpStudent(['phone' => '0912345678']);
    vvContactUpdate(['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
    expect($user->fresh()->phone)->toBe('0912345678');
});
