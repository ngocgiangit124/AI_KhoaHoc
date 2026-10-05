<?php

use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

test('AC1 dang ky xong he thong gui OTP qua email toi email vua dang ky', function () {
    $sender = vvFakeOtp();

    vvRegister(['email' => 'moi@example.com'])->assertCreated()->assertJson(['is_verified' => false]);

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['channel'])->toBe('email')
        ->and($sender->sent[0]['destination'])->toBe('moi@example.com')
        ->and($sender->sent[0]['code'])->toMatch('/^\d{6}$/');
    expect(OtpCode::count())->toBe(1);
});

test('dang ky van thanh cong khi gui OTP gap su co', function () {
    app()->instance(OtpSender::class, new class implements OtpSender
    {
        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            throw new RuntimeException('queue down');
        }
    });

    vvRegister()->assertCreated();
    expect(User::count())->toBe(1);
});

test('ma luu dang hash, khong luu ma ro, co han dung va gan dich', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent();

    vvOtpSend()->assertStatus(202);

    $otp = OtpCode::firstOrFail();
    expect($otp->code_hash)->not->toContain($sender->lastCode())
        ->and(Hash::check($sender->lastCode(), $otp->code_hash))->toBeTrue()
        ->and($otp->destination)->toBe($user->email)
        ->and($otp->channel)->toBe('email')
        ->and($otp->purpose)->toBe(OtpPurpose::VerifyAccount)
        ->and($otp->attempts)->toBe(0)
        ->and((int) round(now()->diffInMinutes($otp->expires_at, true)))->toBe(10);
});

describe('POST /auth/otp/send', function () {
    test('202 + resend_available_at (ISO 8601) va gui ma', function () {
        $sender = vvFakeOtp();
        vvOtpStudent();

        $res = vvOtpSend()->assertStatus(202)->assertJsonStructure(['resend_available_at']);

        expect(Carbon\Carbon::parse($res->json('resend_available_at'))->isFuture())->toBeTrue()
            ->and($sender->sent)->toHaveCount(1);
    });

    test('gui lai ngay (< 60s) -> 429 TOO_MANY_ATTEMPTS + Retry-After', function () {
        vvFakeOtp();
        vvOtpStudent();

        vvOtpSend()->assertStatus(202);
        vvOtpSend()->assertStatus(429)->assertJson(['code' => 'TOO_MANY_ATTEMPTS'])->assertHeader('Retry-After');

        expect(OtpCode::count())->toBe(1);
    });

    test('kenh sms khi chi bat email -> 422 (production MVP)', function () {
        config(['auth.otp.channels' => ['email']]);
        vvFakeOtp();
        vvOtpStudent();

        vvOtpSend(['channel' => 'sms'])->assertStatus(422)->assertJsonValidationErrors('channel');
        expect(OtpCode::count())->toBe(0);
    });

    test('kenh la -> 422', function () {
        vvFakeOtp();
        vvOtpStudent();

        vvOtpSend(['channel' => 'zalo'])->assertStatus(422)->assertJsonValidationErrors('channel');
        expect(OtpCode::count())->toBe(0);
    });

    test('kenh sms duoc gui toi SDT khi cau hinh bat (local/testing)', function () {
        config(['auth.otp.channels' => ['email', 'sms']]);
        $sender = vvFakeOtp();
        $user = vvOtpStudent();

        vvOtpSend(['channel' => 'sms'])->assertStatus(202);

        expect($sender->sent[0]['channel'])->toBe('sms')->and($sender->sent[0]['destination'])->toBe($user->phone);
    });

    test('da xac thuc roi -> 422, khong phat ma', function () {
        vvFakeOtp();
        vvOtpStudent(['email_verified_at' => now()]);

        vvOtpSend()->assertStatus(422)->assertJsonValidationErrors('channel');
        expect(OtpCode::count())->toBe(0);
    });

    test('chua dang nhap -> 401; giao vien -> 403; bi khoa -> 403', function () {
        vvOtpSend()->assertStatus(401);

        $this->actingAs(User::factory()->teacher()->create());
        vvOtpSend()->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);

        $this->actingAs(User::factory()->locked()->create());
        vvOtpSend()->assertStatus(403)->assertJson(['code' => 'ACCOUNT_LOCKED']);
    });
});

describe('POST /auth/otp/verify', function () {
    test('AC8 dung ma -> 200 user is_verified=true, ghi email_verified_at, ma bi consume', function () {
        $sender = vvFakeOtp();
        $user = vvOtpStudent();
        vvOtpSend();

        vvOtpVerify($sender->lastCode())
            ->assertOk()
            ->assertJson(['id' => $user->id, 'is_verified' => true])
            ->assertJsonMissingPath('password');

        $fresh = $user->fresh();
        expect($fresh->email_verified_at)->not->toBeNull()->and($fresh->phone_verified_at)->toBeNull();
        expect(OtpCode::first()->consumed_at)->not->toBeNull();
    });

    test('ma dung nhung da dung roi -> khong dung lai duoc', function () {
        $sender = vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();
        $code = $sender->lastCode();

        vvOtpVerify($code)->assertOk();
        vvOtpVerify($code)->assertStatus(422)->assertJsonValidationErrors('code');
    });

    test('ma sai -> 422 field code, attempts tang, chua xac thuc', function () {
        $sender = vvFakeOtp();
        $user = vvOtpStudent();
        vvOtpSend();
        $wrong = $sender->lastCode() === '000000' ? '111111' : '000000';

        vvOtpVerify($wrong)
            ->assertStatus(422)
            ->assertJson(['code' => 'OTP_INVALID', 'errors' => ['code' => [OtpService::MESSAGE_WRONG]]]);

        expect(OtpCode::first()->attempts)->toBe(1)->and($user->fresh()->email_verified_at)->toBeNull();
    });

    test('ma het han -> 422 thong diep het han', function () {
        $sender = vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();
        $this->travel(11)->minutes();

        vvOtpVerify($sender->lastCode())
            ->assertStatus(422)
            ->assertJson(['code' => 'OTP_EXPIRED', 'errors' => ['code' => [OtpService::MESSAGE_EXPIRED]]]);
    });

    test('chua tung gui ma -> 422', function () {
        vvOtpStudent();

        vvOtpVerify('123456')->assertStatus(422)->assertJsonValidationErrors('code');
    });

    test('dinh dang ma sai (khong phai 6 so) -> 422 truoc khi cham vao ma that', function () {
        config(['auth.otp.max_verify_per_minute' => 100]);
        $sender = vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();

        foreach (['12345', '1234567', 'abcdef', '12 34 5x', ''] as $bad) {
            vvOtpVerify($bad)->assertStatus(422)->assertJsonValidationErrors('code');
        }

        expect(OtpCode::first()->attempts)->toBe(0);
        $this->postJson(vvApiUrl('/auth/otp/verify'), ['code' => ['1']], vvWebHeaders())->assertStatus(422);
    });

    test('sai 5 lan -> lan thu 6 voi ma DUNG van bi chan 429 (het luot moi ma)', function () {
        config(['auth.otp.max_verify_per_minute' => 100]);
        $sender = vvFakeOtp();
        $user = vvOtpStudent();
        vvOtpSend();
        $right = $sender->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            vvOtpVerify($wrong)->assertStatus(422);
        }

        vvOtpVerify($right)->assertStatus(429)->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);

        expect(OtpCode::first()->attempts)->toBe(5)
            ->and($user->fresh()->email_verified_at)->toBeNull();
    });

    test('gui ma moi -> luot dem moi, ma cu bi huy', function () {
        config(['auth.otp.max_verify_per_minute' => 100]);
        $sender = vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();
        $old = $sender->lastCode();
        $this->travel(61)->seconds();
        vvOtpSend()->assertStatus(202);
        $new = $sender->lastCode();

        if ($old !== $new) {
            vvOtpVerify($old)->assertStatus(422);
        }

        vvOtpVerify($new)->assertOk();
        expect(OtpCode::whereNotNull('invalidated_at')->count())->toBe(1);
    });

    test('ma cua nguoi khac khong dung duoc (ma gan voi user)', function () {
        $sender = vvFakeOtp();
        $alice = User::factory()->create();
        app(OtpService::class)->sendVerification($alice, 'email');
        $aliceCode = $sender->lastCode();

        $bob = vvOtpStudent();
        vvOtpVerify($aliceCode)->assertStatus(422);

        expect($bob->fresh()->email_verified_at)->toBeNull()->and($alice->fresh()->email_verified_at)->toBeNull();
    });

    test('ma gui toi email cu khong con gia tri khi email da bi doi ngam (destination khop)', function () {
        $sender = vvFakeOtp();
        $user = vvOtpStudent();
        vvOtpSend();
        // Mô phỏng đổi email không qua ContactService (không huỷ mã): mã không được dùng.
        User::whereKey($user->id)->update(['email' => 'khac@example.com']);
        vvActAsStudent($user->fresh());

        vvOtpVerify($sender->lastCode())->assertStatus(422);
        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('throttle otp-verify: 5/phut -> request thu 6 bi 429 co Retry-After', function () {
        vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();

        foreach (range(1, 5) as $i) {
            vvOtpVerify('000000');
        }

        vvOtpVerify('000000')->assertStatus(429)->assertHeader('Retry-After');
    });

    test('throttle otp-verify: tran 20/ngay theo user (khoa den het ngay)', function () {
        config(['auth.otp.max_verify_per_minute' => 100]);
        vvFakeOtp();
        vvOtpStudent();
        vvOtpSend();

        foreach (range(1, 20) as $i) {
            expect(vvOtpVerify('000000')->headers->has('Retry-After'))->toBeFalse();
        }

        // Lần 21 trong ngày: bị chặn bởi limiter (có Retry-After), không còn chạm vào mã.
        $attempts = OtpCode::first()->attempts;
        vvOtpVerify('000000')->assertStatus(429)->assertHeader('Retry-After');
        expect(OtpCode::first()->attempts)->toBe($attempts);
    });

    test('verify gui nhieu lan co ghi audit account.verified, khong chua ma', function () {
        $sender = vvFakeOtp();
        $user = vvOtpStudent();
        vvOtpSend();
        vvOtpVerify($sender->lastCode())->assertOk();

        $log = AuditLog::where('action', 'account.verified')->firstOrFail();
        expect($log->subject_id)->toBe($user->id)
            ->and(json_encode($log->changes))->not->toContain($sender->lastCode());
    });
});

test('factory OtpCode tao ma 123456 con han', function () {
    $otp = OtpCode::factory()->create();

    expect(Hash::check('123456', $otp->code_hash))->toBeTrue()->and($otp->expires_at->isFuture())->toBeTrue();
});
