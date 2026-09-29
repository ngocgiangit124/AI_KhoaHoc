<?php

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Auth\ContactService;
use App\Services\Auth\OtpService;
use Illuminate\Support\Carbon;

/**
 * T04 security review N1 [Low] — `assertUnderDestinationLimit()` PHẢI kiểm
 * trên địa chỉ SẼ GỬI (địa chỉ MỚI khi đổi liên hệ), không phải địa chỉ CŨ.
 * TRƯỚC ĐÂY kiểm trên địa chỉ cũ (trước `$beforeCreate()`) trong khi
 * `hitDestinationLimit()` lại đếm cho địa chỉ mới — 2 bước lệch nhau, khiến:
 * (a) tài khoản đang giữ 1 địa chỉ đã bị tài khoản KHÁC gửi chạm trần bị kẹt,
 * không đổi SANG địa chỉ khác được; (b) địa chỉ MỚI hoàn toàn không được
 * kiểm trần trước khi gửi.
 */
afterEach(function () {
    Carbon::setTestNow();
});

test('N1: dia chi MOI da cham tran dich (do tai khoan khac gay ra) thi doi lien he bi 429, email KHONG doi', function () {
    config(['auth.otp.max_per_hour_per_destination' => 1, 'auth.otp.max_per_day_per_destination' => 1]);

    $targetAddress = 'shared-target-'.uniqid().'@example.com';

    // Tài khoản KHÁC từng giữ $targetAddress, gửi 1 mã (chạm trần đích =1),
    // rồi đổi đi (giải phóng $targetAddress cho unique constraint) — trần
    // theo ĐÍCH (RateLimiter, không gắn với user nào) vẫn còn hiệu lực.
    $otherHolder = User::factory()->create(['email' => $targetAddress]);
    app(OtpService::class)->send($otherHolder, OtpPurpose::VerifyAccount, 'email');
    User::query()->where('id', $otherHolder->id)->update(['email' => 'freed-'.uniqid().'@example.com']);

    $user = User::factory()->create(['email' => 'a-'.uniqid().'@example.com']);
    $emailBefore = $user->email;

    $error = null;

    try {
        app(ContactService::class)->update($user, ['email' => $targetAddress]);
    } catch (DomainException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->code())->toBe('TOO_MANY_ATTEMPTS');
    expect($user->fresh()->email)->toBe($emailBefore);
});

test('N1: dia chi CU da cham tran dich van doi SANG dia chi khac duoc (khong bi ket)', function () {
    config(['auth.otp.max_per_hour_per_destination' => 1, 'auth.otp.max_per_day_per_destination' => 1]);

    $hotAddress = 'old-hot-'.uniqid().'@example.com';
    $user = User::factory()->create(['email' => $hotAddress]);

    // Chính user này gửi 1 mã tới địa chỉ HIỆN TẠI — chạm trần đích cho
    // $hotAddress (trần =1). Nhích qua cooldown (KHÔNG qua cửa sổ giờ/ngày
    // của trần theo ĐÍCH, vẫn còn hiệu lực) để lần đổi liên hệ sau không bị
    // chặn bởi cooldown theo USER.
    app(OtpService::class)->send($user, OtpPurpose::VerifyAccount, 'email');
    Carbon::setTestNow(now()->addSeconds(61));

    $newAddress = 'fresh-'.uniqid().'@example.com';

    // Đổi liên hệ SANG địa chỉ khác (chưa chạm trần) — không được kiểm nhầm
    // theo $hotAddress (địa chỉ CŨ đang bị bỏ đi), phải kiểm theo $newAddress.
    app(ContactService::class)->update($user, ['email' => $newAddress]);

    expect($user->fresh()->email)->toBe($newAddress);
});
