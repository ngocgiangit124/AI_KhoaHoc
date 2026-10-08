<?php

use App\Mail\ParentNoticeMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

test('(a) hoc sinh 13 tuoi dang ky khong co lien he phu huynh -> 201, not_required, khong thu', function () {
    vvRegister(['date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(13)->toDateString()])
        ->assertCreated()->assertJson(['parent_consent_status' => 'not_required']);

    $user = User::firstOrFail();
    expect($user->parent_consent_status->value)->toBe('not_required')
        ->and($user->parent_email)->toBeNull()
        ->and(vvT29Notices())->toHaveCount(0);
});

test('(a) chuoi trong / chi khoang trang o parent_* coi nhu khong gui', function () {
    vvRegister(['parent_email' => '   ', 'parent_phone' => ''])->assertCreated();

    $user = User::firstOrFail();
    expect($user->parent_email)->toBeNull()->and($user->parent_phone)->toBeNull();
});

test('(b) co parent_email -> dung 1 ParentNoticeMail account_created toi dung dia chi, noi dung khong lo PII hoc sinh', function () {
    $user = vvT29RegisterAndVerify([
        'name' => 'Nguyễn Minh Anh',
        'email' => 'hocsinh.rieng@example.com',
        'phone' => '0987654321',
        'date_of_birth' => '2012-03-04',
        'parent_email' => 'PhuHuynh@Example.com',
    ]);

    $notices = vvT29Notices();
    expect($notices)->toHaveCount(1);

    /** @var ParentNoticeMail $mail */
    $mail = $notices->first();
    expect($mail->kind)->toBe('account_created')
        ->and($mail->hasTo('phuhuynh@example.com'))->toBeTrue()
        ->and($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail->maskedStudentName)->toBe('Nguyễn Minh A**');

    $html = $mail->render();
    expect($html)->toContain('Nguyễn Minh A')
        ->and($html)->not->toContain('Nguyễn Minh Anh')
        ->and($html)->not->toContain('hocsinh.rieng@example.com')
        ->and($html)->not->toContain('0987654321')->and($html)->not->toContain('987654321')
        ->and($html)->not->toContain('2012')->and($html)->not->toContain('04/03')
        ->and($html)->toContain('huy-nhan-thong-bao?t=');

    // Header List-Unsubscribe one-click (RFC 8058)
    $headers = $mail->headers()->text;
    expect($headers['List-Unsubscribe'])->toStartWith('<')->and($headers['List-Unsubscribe'])->toContain('/api/v1/parent-notices/unsubscribe?t=')
        ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');

    // Audit: sent {kind}, actor null, khong co dia chi
    $audit = AuditLog::query()->where('action', 'parent_notice.sent')->where('subject_id', $user->id)->firstOrFail();
    expect($audit->actor_id)->toBeNull()->and($audit->changes)->toBe(['kind' => 'account_created'])
        ->and(json_encode($audit->getAttributes()))->not->toContain('phuhuynh');
});

test('R1: dang ky xong CHUA xac thuc OTP -> khong thu nao cho phu huynh; xac thuc lan dau -> 1 thu; xac thuc them kenh khac -> khong them', function () {
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => 'ph@example.com'])->assertCreated();
    expect(vvT29Notices())->toHaveCount(0);

    $user = User::firstOrFail();
    app(OtpService::class)->verifyAccount($user, $otp->lastCode());
    expect(vvT29Notices('account_created'))->toHaveCount(1);
});

test('(b) chi co parent_phone -> khong gui thu (chua co kenh SMS)', function () {
    vvT29RegisterAndVerify(['parent_phone' => '0911111111']);

    expect(User::firstOrFail()->parent_phone)->toBe('0911111111')->and(vvT29Notices())->toHaveCount(0);
});

test('(c) parent_email = email hoc sinh (sau chuan hoa) -> 422 field parent_email; parent_phone = SDT dang +84 -> 422', function () {
    vvRegister(['email' => 'an@example.com', 'parent_email' => ' AN@Example.com '])
        ->assertStatus(422)->assertJsonValidationErrors('parent_email')
        ->assertJsonPath('errors.parent_email.0', 'Email phụ huynh phải khác email của bạn.');

    vvRegister(['phone' => '0912345678', 'parent_phone' => '+84 912 345 678'])
        ->assertStatus(422)->assertJsonValidationErrors('parent_phone')
        ->assertJsonPath('errors.parent_phone.0', 'Số điện thoại phụ huynh phải khác số của bạn.');

    expect(User::count())->toBe(0)->and(vvT29Notices())->toHaveCount(0);
});

test('(d) 20 tuoi nhap parent_email -> luu (DB la ciphertext) va co thu', function () {
    vvT29RegisterAndVerify([
        'date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(20)->toDateString(),
        'parent_email' => 'ph20@example.com',
    ]);

    $user = User::firstOrFail();
    expect($user->parent_email)->toBe('ph20@example.com')
        ->and(DB::table('users')->where('id', $user->id)->value('parent_email'))->not->toContain('example.com')
        ->and(vvT29Notices('account_created'))->toHaveCount(1);
});

test('(j) 6 hoc sinh cung mot email phu huynh dang ky trong ngay -> chi 5 thu', function () {
    foreach (range(1, 6) as $i) {
        vvT29RegisterAndVerify([
            'email' => "hs{$i}@example.com",
            'phone' => '09123456'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'parent_email' => 'chung@example.com',
        ]);
        vvResetClient();
    }

    expect(User::count())->toBe(6)->and(vvT29Notices())->toHaveCount(5);
});

test('(k) co parent_notices tat -> khong thu, dang ky van 201', function () {
    config(['features.parent_notices' => false]);

    vvT29RegisterAndVerify(['parent_email' => 'ph@example.com']);

    expect(User::firstOrFail()->parent_email)->toBe('ph@example.com')->and(vvT29Notices())->toHaveCount(0);
});

test('thu loi khi gui khong lam hong dang ky', function () {
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => 'ph@example.com'])->assertCreated();
    $user = User::firstOrFail();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $verified = app(OtpService::class)->verifyAccount($user, $otp->lastCode());

    expect($verified->isVerified())->toBeTrue();
});

test('phu huynh da huy nhan: tai khoan moi cung email khac van gui; opt-out khong ap cho user khac', function () {
    $other = User::factory()->student()->create(['parent_email' => 'x@example.com', 'parent_notice_opt_out_at' => now()]);
    expect($other->parent_notice_opt_out_at)->not->toBeNull();

    vvT29RegisterAndVerify(['parent_email' => 'x@example.com']);

    expect(vvT29Notices())->toHaveCount(1);
});
