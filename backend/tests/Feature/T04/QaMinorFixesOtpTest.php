<?php

use App\Models\OtpCode;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/helpers.php';

// QA gom sửa lỗi nhỏ (minor-fixes-1): OTP_INVALID / OTP_EXPIRED / hết lượt 429, errors.code[] còn nguyên.

beforeEach(fn () => Cache::flush());

test('QA minor-fixes: verify sai -> 422 OTP_INVALID, errors.code[] con', function () {
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend()->assertStatus(202);
    $wrong = $sender->lastCode() === '000000' ? '111111' : '000000';

    $r = vvOtpVerify($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    expect($r->json('errors.code'))->toBeArray()->toHaveCount(1)->and($r->json('errors.code.0'))->toBeString()->not->toBe('');
});

test('QA minor-fixes: verify het han -> 422 OTP_EXPIRED; chua tung gui ma cung OTP_EXPIRED', function () {
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpVerify('123456')->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED')->assertJsonStructure(['errors' => ['code']]);

    vvOtpSend()->assertStatus(202);
    $this->travel(11)->minutes();
    $r = vvOtpVerify($sender->lastCode())->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    expect($r->json('errors.code'))->toBeArray()->not->toBeEmpty();
});

test('QA minor-fixes: het luot nhap -> 429 TOO_MANY_ATTEMPTS (khong phai 422 OTP_*)', function () {
    config(['auth.otp.max_attempts_per_code' => 2]);
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend()->assertStatus(202);
    $wrong = $sender->lastCode() === '000000' ? '111111' : '000000';

    vvOtpVerify($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    vvOtpVerify($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    vvOtpVerify($sender->lastCode())->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    expect(OtpCode::first()->consumed_at)->toBeNull();
});

test('QA minor-fixes: validation thuong (thieu code) van la VALIDATION_ERROR, khong bi nhan nham OTP_*', function () {
    vvFakeOtp();
    vvOtpStudent();
    test()->postJson(vvApiUrl('/auth/otp/verify'), [], vvWebHeaders())->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
});
