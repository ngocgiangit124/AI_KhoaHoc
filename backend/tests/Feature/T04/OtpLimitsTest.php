<?php

use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/** Gọi service trực tiếp (không qua throttle middleware) để kiểm trần ở TẦNG DB. */
function vvIssue(User $user, bool $cooldown = true): void
{
    app(OtpService::class)->sendVerification($user, 'email', $cooldown);
}

test('cooldown 60s: gui lai som -> 429 Retry-After <= 60; sau 61s gui duoc', function () {
    vvFakeOtp();
    $user = User::factory()->create();

    vvIssue($user);

    try {
        vvIssue($user);
        $this->fail('Phải bị chặn bởi cooldown');
    } catch (ThrottleRequestsException $e) {
        expect((int) $e->getHeaders()['Retry-After'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    }

    $this->travel(61)->seconds();
    vvIssue($user);

    expect(OtpCode::count())->toBe(2);
});

test('toi da 5 ma/gio -> ma thu 6 bi chan, het gio lai duoc', function () {
    vvFakeOtp();
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        vvIssue($user);
        $this->travel(61)->seconds();
    }

    expect(fn () => vvIssue($user))->toThrow(ThrottleRequestsException::class);
    expect(OtpCode::count())->toBe(5);

    $this->travel(56)->minutes();
    vvIssue($user);
    expect(OtpCode::count())->toBe(6);
});

test('toi da 10 ma/ngay -> ma thu 11 bi chan va ghi audit; sau 24h lai duoc', function () {
    vvFakeOtp();
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        vvIssue($user);
        $this->travel(20)->minutes(); // 3 mã/giờ: không chạm trần giờ
    }

    $before = OtpCode::count();
    expect(fn () => vvIssue($user))->toThrow(ThrottleRequestsException::class);
    expect(OtpCode::count())->toBe($before)->and($before)->toBe(10);

    $audit = AuditLog::where('action', 'otp.send_limit_reached')->first();
    expect($audit)->not->toBeNull()->and($audit->subject_id)->toBe($user->id);

    $this->travel(21)->hours();
    vvIssue($user);
    expect(OtpCode::count())->toBe(11);
});

test('tran gui ma tinh theo user, khong anh huong user khac', function () {
    vvFakeOtp();
    $a = User::factory()->create();
    $b = User::factory()->create();

    vvIssue($a);

    expect(fn () => vvIssue($a))->toThrow(ThrottleRequestsException::class);
    vvIssue($b);
    expect(OtpCode::where('user_id', $b->id)->count())->toBe(1);
});

test('phat ma moi huy ma cu cung purpose (khong dung lai ma cu)', function () {
    $sender = vvFakeOtp();
    $user = User::factory()->create();
    $service = app(OtpService::class);

    vvIssue($user);
    $old = $sender->lastCode();
    $this->travel(61)->seconds();
    vvIssue($user);
    $new = $sender->lastCode();

    if ($old !== $new) {
        expect(fn () => $service->verifyAccount($user, $old))->toThrow(ValidationException::class);
    }

    expect($service->verifyAccount($user->fresh(), $new)->email_verified_at)->not->toBeNull();
});

test('ma sinh bang random_int: luon dung 6 chu so (ke ca bat dau bang 0)', function () {
    $sender = vvFakeOtp();
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        app(OtpService::class)->issue($user, OtpPurpose::VerifyAccount, 'email', $user->email, enforceCooldown: false);
    }

    foreach ($sender->sent as $row) {
        expect($row['code'])->toMatch('/^\d{6}$/');
    }
});

test('OtpService khong dung rand()/mt_rand() de sinh ma', function () {
    $source = file_get_contents(app_path('Services/Auth/Otp/OtpService.php'));

    expect($source)->toContain('random_int(0, 999999)')
        ->and($source)->not->toMatch('/\b(mt_rand|rand|uniqid|shuffle)\s*\(/');
});
