<?php

use App\Mail\ContactChangedMail;
use App\Models\AuditLog;
use App\Services\Auth\ContactService;
use App\Services\Auth\StudentSessionService;
use App\Support\AtomicCounter;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

/**
 * H1 (review bảo mật cụm 1): đổi email phải xác thực lại bằng mật khẩu hiện tại, báo email cũ, huỷ phiên khác.
 */
test('thiếu current_password -> 422 field current_password, email không đổi', function () {
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);

    test()->putJson(vvApiUrl('/auth/contact'), ['email' => 'moi@example.com'], vvWebHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect($user->fresh()->email)->toBe('cu@example.com');
});

test('sai current_password -> 422, có audit, không đổi, không gửi mã/thư', function () {
    Mail::fake();
    $sender = vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);

    vvContactUpdate(['email' => 'moi@example.com', 'current_password' => 'sai-mat-khau'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password')
        ->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không đúng.');

    $fresh = $user->fresh();
    expect($fresh->email)->toBe('cu@example.com')->and($fresh->email_verified_at)->not->toBeNull()
        ->and($sender->sent)->toBeEmpty();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    $log = AuditLog::where('action', 'account.contact_change_failed')->first();
    expect($log)->not->toBeNull()->and($log->actor_id)->toBe($user->id)
        ->and(json_encode($log->changes))->not->toContain('moi@example.com')->not->toContain('sai-mat-khau');
});

test('đúng current_password -> 200 và đổi email', function () {
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);

    vvContactUpdate(['email' => 'moi@example.com'])->assertOk();

    expect($user->fresh()->email)->toBe('moi@example.com');
});

test('KHÔNG miễn cho tài khoản chưa xác thực: vẫn bắt buộc mật khẩu (quyết định H1)', function () {
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'chuaxt@example.com', 'email_verified_at' => null]);

    expect($user->isVerified())->toBeFalse();
    test()->putJson(vvApiUrl('/auth/contact'), ['email' => 'khac@example.com'], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('current_password');
    vvContactUpdate(['email' => 'khac@example.com', 'current_password' => 'sai'])->assertStatus(422);
    expect($user->fresh()->email)->toBe('chuaxt@example.com');

    vvContactUpdate(['email' => 'khac@example.com'])->assertOk();
    expect($user->fresh()->email)->toBe('khac@example.com');
});

test('sai mật khẩu nhiều lần -> 429 (dùng chung limiter password-change)', function () {
    vvFakeOtp();
    $user = vvOtpStudent();

    foreach (range(1, 5) as $i) {
        vvContactUpdate(['email' => 'x@example.com', 'current_password' => 'sai-'.$i])->assertStatus(422);
    }

    vvContactUpdate(['email' => 'x@example.com', 'current_password' => 'sai-6'])->assertStatus(429);
    // Trần theo phút áp cả khi mật khẩu đúng: không dò được mật khẩu bằng route này.
    vvContactUpdate(['email' => 'x@example.com'])->assertStatus(429);
    expect($user->fresh()->email)->not->toBe('x@example.com');
});

test('bộ đếm lượt sai nguyên tử dùng chung: đổi mật khẩu và đổi liên hệ trừ cùng một hạn mức', function () {
    vvFakeOtp();
    $user = vvOtpStudent();
    // Tránh trần theo phút của middleware để chỉ kiểm bộ đếm trong service.
    $key = 'current-password-fail:u:'.$user->id;
    foreach (range(1, 9) as $i) {
        AtomicCounter::hit($key, 3600);
    }

    vvContactUpdate(['email' => 'x@example.com', 'current_password' => 'sai'])->assertStatus(422);
    // Lượt thứ 11 (kể cả đúng mật khẩu): bị chặn trước khi so mật khẩu.
    vvContactUpdate(['email' => 'x@example.com'])->assertStatus(429);
    test()->putJson(vvApiUrl('/auth/password'), ['current_password' => 'password', 'password' => 'mat-khau-moi-xyz-1', 'password_confirmation' => 'mat-khau-moi-xyz-1'], vvWebHeaders())->assertStatus(429);
});

test('mật khẩu đúng hoàn lượt đã giữ chỗ (chỉ đếm lượt sai)', function () {
    vvFakeOtp();
    $user = vvOtpStudent();

    vvContactUpdate(['email' => 'a@example.com'])->assertOk();

    expect(AtomicCounter::attempts('current-password-fail:u:'.$user->id))->toBe(0);
});

test('đổi email của tài khoản đã xác thực: thư báo tới email CŨ (che email mới), không lộ email mới', function () {
    Mail::fake();
    vvFakeOtp();
    $user = vvOtpStudent(['name' => 'Nguyễn An', 'email' => 'cu@example.com', 'email_verified_at' => now()]);

    vvContactUpdate(['email' => 'ke.chiem@example.org'])->assertOk();

    Mail::assertQueued(ContactChangedMail::class, function (ContactChangedMail $m) {
        $html = $m->render();

        return $m->hasTo('cu@example.com')
            && $m->maskedNewEmail === 'k***@example.org'
            && ! str_contains($html, 'ke.chiem')
            && str_contains($html, 'k***@example.org')
            && str_contains($html, config('ops.support_email'))
            && $m->changedAt !== '';
    });
    Mail::assertQueuedCount(1);
});

test('email cũ chưa xác thực: không gửi thư báo (không tin địa chỉ đó), vẫn đổi được', function () {
    Mail::fake();
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => null]);

    vvContactUpdate(['email' => 'moi@example.com'])->assertOk();

    Mail::assertNotQueued(ContactChangedMail::class);
    expect($user->fresh()->email)->toBe('moi@example.com');
});

test('chỉ đổi SĐT hoặc không đổi gì: không gửi thư báo, không huỷ phiên', function () {
    Mail::fake();
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);
    $session = $user->current_session_id;

    vvContactUpdate(['phone' => '0987654321'])->assertOk();
    vvContactUpdate(['email' => 'cu@example.com'])->assertOk();

    Mail::assertNothingQueued();
    expect($user->fresh()->current_session_id)->toBe($session);
});

test('đổi email huỷ phiên khác (tombstone contact_changed) và giữ phiên hiện tại', function () {
    Mail::fake();
    vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);
    $old = $user->current_session_id;

    vvContactUpdate(['email' => 'moi@example.com'])->assertOk();

    $fresh = $user->fresh();
    expect($fresh->current_session_id)->not->toBe($old)
        ->and($fresh->current_session_id)->not->toBe(StudentSessionService::LOGGED_OUT)
        ->and(StudentSessionService::tombstone($old)['reason'])->toBe(StudentSessionService::REASON_CONTACT_CHANGED);
});

test('maskEmail che phần local, giữ tên miền', function () {
    expect(ContactService::maskEmail('abc@example.com'))->toBe('a***@example.com')
        ->and(ContactService::maskEmail('Z@x.vn'))->toBe('Z***@x.vn');
});
