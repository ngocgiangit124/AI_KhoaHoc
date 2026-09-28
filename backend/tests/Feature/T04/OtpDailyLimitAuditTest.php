<?php

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * T04 review R2 — data-model §3.1: "vượt trần ngày → khoá xác thực 24h + ghi
 * `audit_logs`" (action `otp.daily_limit`). Test qua HTTP thật (không gọi
 * thẳng `AppServiceProvider`) để khẳng định đúng hành vi observable: request
 * thứ N+1 (N = trần ngày) bị 429 VÀ có đúng 1 bản ghi audit — không dùng
 * `Limit::response()` (xem comment `AppServiceProvider::auditOnceIfDailyLimitReached()`
 * giải thích lý do tránh vỡ `ApiExceptionRenderer`).
 */
afterEach(function () {
    Carbon::setTestNow();
});

function vvOtpSendForAudit(User $user)
{
    return test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/send', [
        'channel' => 'email',
    ], [
        'Origin' => config('app.frontend_url'),
    ]);
}

function vvOtpVerifyForAudit(User $user, string $code)
{
    return test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/verify', [
        'code' => $code,
    ], [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('vuot tran otp-send/ngay ghi dung 1 ban ghi audit otp.daily_limit, khong lap lai (R2)', function () {
    config(['auth.otp.max_per_hour' => 999]);
    Mail::fake();

    $user = User::factory()->create();
    $maxPerDay = (int) config('auth.otp.max_per_day');

    for ($i = 0; $i < $maxPerDay; $i++) {
        vvOtpSendForAudit($user)->assertStatus(202);
        Carbon::setTestNow(now()->addSeconds(61));
    }

    expect(AuditLog::query()->where('action', 'otp.daily_limit')->count())->toBe(0);

    vvOtpSendForAudit($user)->assertStatus(429);

    $logs = AuditLog::query()->where('action', 'otp.daily_limit')->get();
    expect($logs)->toHaveCount(1);
    expect($logs->first()->actor_id)->toBe($user->id);

    // Các lần bị chặn tiếp theo trong cùng ngày KHÔNG ghi thêm audit (dedup).
    Carbon::setTestNow(now()->addSeconds(61));
    vvOtpSendForAudit($user)->assertStatus(429);
    Carbon::setTestNow(now()->addSeconds(61));
    vvOtpSendForAudit($user)->assertStatus(429);

    expect(AuditLog::query()->where('action', 'otp.daily_limit')->count())->toBe(1);
});

test('vuot tran otp-verify/ngay ghi dung 1 ban ghi audit otp.daily_limit (R2)', function () {
    config(['auth.otp.max_verify_per_minute' => 999, 'auth.otp.max_attempts_per_code' => 999]);

    $user = User::factory()->create();
    OtpCode::factory()->for($user)->create(['code_hash' => Hash::make('654321')]);
    $maxPerDay = (int) config('auth.otp.max_verify_per_day');

    for ($i = 0; $i < $maxPerDay; $i++) {
        vvOtpVerifyForAudit($user, '000000')->assertStatus(422);
    }

    expect(AuditLog::query()->where('action', 'otp.daily_limit')->count())->toBe(0);

    vvOtpVerifyForAudit($user, '000000')->assertStatus(429);

    expect(AuditLog::query()->where('action', 'otp.daily_limit')->count())->toBe(1);
});
