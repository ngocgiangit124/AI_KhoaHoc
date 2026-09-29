<?php

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * T04 review R1 [BLOCKER] — `PUT /auth/contact` PHẢI bị chặn bởi trần gửi OTP
 * (S9: cooldown 60s, ≤5/giờ, ≤10/ngày/user) giống hệt `POST /auth/otp/send`,
 * dù không đi qua route đó. Trước khi sửa, 1 học sinh có thể đổi email liên
 * tục sang các địa chỉ khác nhau (kể cả toggling qua lại địa chỉ của người
 * khác) để gửi OTP thật không giới hạn — "email bombing". Sửa 2 lớp:
 * 1. `throttle:otp-send` gắn trực tiếp lên route (routes/api.php) — chặn sớm
 *    ở tầng HTTP, dùng chung bộ đếm với `/auth/otp/send` (cùng định danh user).
 * 2. `OtpService::createCodeAtomically()` (dùng bởi `send()` và
 *    `sendAfterContactChange()`) tự đếm số `otp_codes` thật đã tạo trong DB
 *    — nguồn sự thật độc lập, bảo vệ MỌI caller kể cả khi 1 route tương lai
 *    quên gắn middleware throttle.
 *
 * Quyết định xử lý khi vượt trần (R1, siết chặt thêm ở M2): TỪ CHỐI 429
 * TRƯỚC KHI đổi bất kỳ thông tin liên hệ nào — `ContactService::update()`
 * gọi `OtpService::sendAfterContactChange()`, kiểm trần + đổi liên hệ trong
 * CÙNG 1 transaction có khoá hàng user (không chỉ 2 bước tách rời như trước)
 * — không đổi email/SĐT "nửa vời" rồi mới phát hiện không gửi được OTP,
 * đúng cả khi có request đồng thời. Fail-closed, không mở lỗ hổng nào khác.
 */
afterEach(function () {
    Carbon::setTestNow();
});

function vvUpdateContactThrottle(User $user, string $email)
{
    return test()->actingAs($user)->putJson('http://'.config('app.api_host').'/api/v1/auth/contact', [
        'email' => $email,
    ], [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('PUT /auth/contact lien tuc vuot tran 10/ngay bi chan 429, khong tao them otp_codes/mail (R1)', function () {
    // Cô lập đúng trần NGÀY đang kiểm (10/ngày — auth.otp.max_per_day mặc
    // định) khỏi trần GIỜ (mặc định 5/giờ, sẽ chặn sớm hơn nếu giữ nguyên).
    config(['auth.otp.max_per_hour' => 999]);
    Mail::fake();

    $user = User::factory()->verified()->create(['email' => 'khoi-dau@example.com']);
    $maxPerDay = (int) config('auth.otp.max_per_day');

    for ($i = 1; $i <= $maxPerDay; $i++) {
        $response = vvUpdateContactThrottle($user, "email-{$i}@example.com");
        $response->assertOk();

        // Mỗi lần gọi cách nhau > cooldown (60s) để không bị chặn ở lớp cooldown.
        Carbon::setTestNow(now()->addSeconds(61));
    }

    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe($maxPerDay);
    Mail::assertQueued(OtpMail::class, $maxPerDay);

    $emailBeforeBlocked = $user->fresh()->email;

    $blocked = vvUpdateContactThrottle($user, 'vuot-tran@example.com');

    $blocked->assertStatus(429);
    // Fail-closed: KHÔNG đổi email khi bị chặn (test quyết định xử lý ở R1).
    expect($user->fresh()->email)->toBe($emailBeforeBlocked);
    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe($maxPerDay);
    Mail::assertQueued(OtpMail::class, $maxPerDay);

    // Gọi thêm vài lần nữa vẫn tiếp tục bị chặn, không rò rỉ thêm otp_codes/mail.
    Carbon::setTestNow(now()->addSeconds(61));
    vvUpdateContactThrottle($user, 'vuot-tran-2@example.com')->assertStatus(429);
    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe($maxPerDay);
    Mail::assertQueued(OtpMail::class, $maxPerDay);
});

test('goi PUT /auth/contact 2 lan lien tiep (khong cho cooldown) bi chan boi throttle:otp-send o tang route', function () {
    Mail::fake();
    $user = User::factory()->verified()->create(['email' => 'a@example.com']);

    vvUpdateContactThrottle($user, 'b@example.com')->assertOk();

    // Không nhích đồng hồ — vẫn trong 60s cooldown, dù đổi sang 1 email khác.
    $blocked = vvUpdateContactThrottle($user, 'c@example.com');

    $blocked->assertStatus(429);
    expect($user->fresh()->email)->toBe('b@example.com');
});
