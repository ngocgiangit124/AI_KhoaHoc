<?php

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

/**
 * H1 (review bảo mật cụm 1): mã đặt lại mật khẩu chỉ gửi/nhận qua email ĐÃ XÁC THỰC. Email chưa xác thực xử lý như
 * không có kênh, và response vẫn y hệt tài khoản không tồn tại (không lộ thông tin).
 */
test('forgot với email chưa xác thực: không gửi mã, response y hệt tài khoản không tồn tại', function () {
    $sender = vvFakeOtp();
    vvPwStudent(['email' => 'chuaxt@example.com', 'phone' => '0911222333', 'email_verified_at' => null]);

    $unverified = (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => 'chuaxt@example.com', 'captcha_token' => 'ok']);
    $unknown = (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => 'khong.co@example.com', 'captcha_token' => 'ok']);

    expect($unverified->status())->toBe(202)->and($unknown->status())->toBe(202);
    // Cùng thông điệp và cùng hình dạng body (resend_available_at có thể lệch vài giây theo thời điểm gọi).
    expect(Arr::except($unverified->json(), ['request_id', 'resend_available_at']))->toBe(Arr::except($unknown->json(), ['request_id', 'resend_available_at']))
        ->and(array_keys($unverified->json()))->toBe(array_keys($unknown->json()));

    expect($sender->sent)->toBeEmpty()->and(OtpCode::count())->toBe(0);
});

test('forgot với email đã xác thực: vẫn gửi mã như cũ', function () {
    $sender = vvFakeOtp();
    vvPwStudent(['email_verified_at' => now()]);

    vvForgot()->assertStatus(202);

    expect($sender->sent)->toHaveCount(1)->and($sender->sent[0]['purpose'])->toBe('reset_password');
});

test('chuỗi H1: đổi email sang địa chỉ mới (chưa xác thực) rồi forgot -> không có mã nào tới email mới', function () {
    $sender = vvFakeOtp();
    $user = vvPwStudent(['email' => 'chu@example.com', 'email_verified_at' => now()]);
    vvActAsStudent($user);

    test()->putJson(vvApiUrl('/auth/contact'), ['email' => 'ke.chiem@example.com', 'current_password' => 'mat-khau-cu-1'], vvWebHeaders())->assertOk();
    $sentAfterChange = count($sender->sent); // chỉ mã VERIFY gửi tới email mới

    foreach ($sender->sent as $s) {
        expect($s['purpose'])->not->toBe('reset_password');
    }

    $kẻChiếm = new VvPwBrowser;
    $kẻChiếm->call('POST', '/auth/password/forgot', ['login' => 'ke.chiem@example.com', 'captcha_token' => 'ok'])->assertStatus(202);
    $kẻChiếm->call('POST', '/auth/password/reset', [
        'login' => 'ke.chiem@example.com', 'code' => '123456', 'password' => 'Mat-khau-cua-ke-chiem-9', 'password_confirmation' => 'Mat-khau-cua-ke-chiem-9',
    ])->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');

    expect($sender->sent)->toHaveCount($sentAfterChange)
        ->and(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
});

test('reset bằng mã reset cũ bị từ chối khi email hiện tại đã đổi và chưa xác thực', function () {
    vvFakeOtp();
    $user = vvPwStudent(['email_verified_at' => now()]);
    $code = vvIssueResetCode($user);

    User::query()->whereKey($user->id)->update(['email' => 'moi@example.com', 'email_verified_at' => null]);

    vvReset($code, ['login' => 'moi@example.com'])->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
});
