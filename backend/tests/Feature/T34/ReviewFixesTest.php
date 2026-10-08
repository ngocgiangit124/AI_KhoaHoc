<?php

use App\Jobs\FinalizeAccountDeletionJob;
use App\Models\Consent;
use App\Models\OtpCode;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Privacy\AccountDeletionFinalizer;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

// ---------------------------------------------------------------------------------------------------------------------
// S1: model cũ + anonymized_at đặt giữa chừng -> không ghi gì vào dòng đã ẩn danh
// ---------------------------------------------------------------------------------------------------------------------

/** Học sinh đã đăng nhập (model trong bộ nhớ còn cũ), rồi pha A "commit" giữa chừng. */
function vvT34StaleAnonymized(): array
{
    $me = vvT34Student(['email' => 'cu@example.com', 'phone' => '0912345678']);
    $before = User::query()->find($me->id)->getAttributes();
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now()]);
    $before['anonymized_at'] = User::query()->find($me->id)->getAttributes()['anonymized_at'];

    return [$me, $before];
}

function vvT34AssertUntouched(User $me, array $before): void
{
    expect(User::query()->find($me->id)->getAttributes())->toBe($before)
        ->and(OtpCode::query()->where('user_id', $me->id)->count())->toBe(0)
        ->and(Consent::query()->where('user_id', $me->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
}

test('S1 PUT /auth/contact voi model cu -> 401 SESSION_REVOKED, khong ghi email/ma/thu', function () {
    Mail::fake();
    $otp = vvFakeOtp();
    [$me, $before] = vvT34StaleAnonymized();

    test()->putJson(vvApiUrl('/auth/contact'), ['email' => 'moi@example.com', 'current_password' => 'password'], vvWebHeaders())
        ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

    vvT34AssertUntouched($me, $before);
    expect($otp->sent)->toBe([]);
});

test('S1 PUT /auth/password voi model cu -> 401, mat khau khong doi', function () {
    Mail::fake();
    [$me, $before] = vvT34StaleAnonymized();

    test()->putJson(vvApiUrl('/auth/password'), [
        'current_password' => 'password', 'password' => 'mat-khau-moi-9', 'password_confirmation' => 'mat-khau-moi-9',
    ], vvWebHeaders())->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

    vvT34AssertUntouched($me, $before);
});

test('S1 PUT /me/parent-contact voi model cu -> 401, khong ghi email/SDT phu huynh, khong thu', function () {
    Mail::fake();
    [$me, $before] = vvT34StaleAnonymized();

    test()->putJson(vvApiUrl('/me/parent-contact'), ['current_password' => 'password', 'parent_email' => 'moi-ph@example.com'], vvWebHeaders())
        ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

    vvT34AssertUntouched($me, $before);
});

test('S1 POST /me/consents/accept voi model cu -> 401, khong ghi consents', function () {
    Mail::fake();
    [$me, $before] = vvT34StaleAnonymized();

    test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => '2026-10-tam', 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())
        ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

    vvT34AssertUntouched($me, $before);
});

test('S1 gui OTP (xoa tai khoan) voi model cu -> 401, khong tao ma, khong gui', function () {
    Mail::fake();
    $otp = vvFakeOtp();
    [$me, $before] = vvT34StaleAnonymized();

    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

    vvT34AssertUntouched($me, $before);
    expect($otp->sent)->toBe([]);
});

test('S1 tai khoan CON SONG van dung binh thuong qua helper khoa', function () {
    $me = vvT34Student();

    test()->putJson(vvApiUrl('/me/parent-contact'), ['current_password' => 'password', 'parent_phone' => '0933333333'], vvWebHeaders())->assertOk();
    test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => '2026-10-tam', 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())->assertOk();
    expect($me->fresh()->parent_phone)->toBe('0933333333');
});

// ---------------------------------------------------------------------------------------------------------------------
// R2: pha B phát lại có trễ khi còn đơn link sống
// ---------------------------------------------------------------------------------------------------------------------

test('R2 finalize tra false khi giu don co link song, true khi da xong', function () {
    $u = vvT34Create();
    DB::table('users')->where('id', $u->id)->update(['anonymized_at' => now(), 'email' => null, 'phone' => null]);
    $order = vvT34PendingOrder($u, 'pending', now()->addMinutes(20));

    expect(app(AccountDeletionFinalizer::class)->finalize($u->id))->toBeFalse();

    DB::table('payment_attempts')->where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);
    expect(app(AccountDeletionFinalizer::class)->finalize($u->id))->toBeTrue()
        ->and(app(AccountDeletionFinalizer::class)->finalize($u->id))->toBeTrue();
});

test('R2 job tu phat lai sau 15 phut voi vong +1 khi con don song; het 6 vong thi dung; xong thi khong phat lai', function () {
    Queue::fake();
    $u = vvT34Create();
    DB::table('users')->where('id', $u->id)->update(['anonymized_at' => now(), 'email' => null, 'phone' => null]);
    vvT34PendingOrder($u, 'pending', now()->addMinutes(20));

    (new FinalizeAccountDeletionJob($u->id))->handle(app(AccountDeletionFinalizer::class));
    Queue::assertPushed(FinalizeAccountDeletionJob::class, function ($j) use ($u) {
        return $j->userId === $u->id && $j->round === 2 && $j->delay !== null
            && now()->addMinutes(15)->diffInSeconds($j->delay, true) < 5;
    });

    Queue::fake();
    (new FinalizeAccountDeletionJob($u->id, FinalizeAccountDeletionJob::MAX_ROUNDS))->handle(app(AccountDeletionFinalizer::class));
    Queue::assertNothingPushed();

    Queue::fake();
    $done = vvT34Create();
    DB::table('users')->where('id', $done->id)->update(['anonymized_at' => now(), 'email' => null, 'phone' => null]);
    (new FinalizeAccountDeletionJob($done->id))->handle(app(AccountDeletionFinalizer::class));
    Queue::assertNothingPushed();
});

test('R4 job: timeout 60, uniqueFor >= tong backoff, failed() chi log user_id + ten class', function () {
    $job = new FinalizeAccountDeletionJob(7, 3);

    expect($job->timeout)->toBe(60)->and($job->uniqueFor)->toBeGreaterThanOrEqual(array_sum($job->backoff))
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);

    Log::shouldReceive('channel')->once()->with('privacy')->andReturnSelf();
    Log::shouldReceive('error')->once()->with('privacy.account_deletion_failed', [
        'user_id' => 7, 'round' => 3, 'exception' => RuntimeException::class,
    ]);

    $job->failed(new RuntimeException('an@example.com 0912345678'));
});

// ---------------------------------------------------------------------------------------------------------------------
// S3 / S4: xoá file ảnh sau commit
// ---------------------------------------------------------------------------------------------------------------------

function vvT34Avatar(): string
{
    $name = (string) Str::uuid().'.webp';
    Storage::disk('uploads')->put($name, 'img');

    return $name;
}

test('S3 erase() trong transaction ngoai bi rollback -> file anh con nguyen, dong ho so con; commit moi xoa file', function () {
    Storage::fake('uploads');
    $teacher = User::factory()->teacher()->create();
    $file = vvT34Avatar();
    TeacherProfile::factory()->create(['user_id' => $teacher->id, 'avatar_path' => $file]);

    try {
        DB::transaction(function () use ($teacher): void {
            app(TeacherProfileService::class)->erase($teacher);

            throw new RuntimeException('pha A rollback');
        });
    } catch (RuntimeException) {
    }

    Storage::disk('uploads')->assertExists($file);
    expect(TeacherProfile::query()->where('user_id', $teacher->id)->count())->toBe(1);

    app(TeacherProfileService::class)->erase($teacher);

    Storage::disk('uploads')->assertMissing($file);
    expect(TeacherProfile::query()->where('user_id', $teacher->id)->count())->toBe(0);
});

test('S3 xoa tai khoan that bai (409 muon) -> anh ho so GV con nguyen', function () {
    Storage::fake('uploads');
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $file = vvT34Avatar();
    TeacherProfile::factory()->create(['user_id' => $me->id, 'avatar_path' => $file]);
    $code = vvT34OtpForDelete($otp);
    vvT34PendingOrder($me, 'pending', now()->addMinutes(20));

    vvT34Delete($code)->assertStatus(409);

    Storage::disk('uploads')->assertExists($file);
});

test('S4 xoa tai khoan xoa file anh o cot cu users.avatar_path sau commit', function () {
    Storage::fake('uploads');
    $otp = vvFakeOtp();
    $file = vvT34Avatar();
    $me = vvT34Student(['avatar_path' => $file]);
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    Storage::disk('uploads')->assertMissing($file);
    expect($me->fresh()->avatar_path)->toBeNull();
});

test('S4 xoa that bai thi file cot cu con nguyen', function () {
    Storage::fake('uploads');
    $otp = vvFakeOtp();
    $file = vvT34Avatar();
    $me = vvT34Student(['avatar_path' => $file]);
    $code = vvT34OtpForDelete($otp);
    vvT34PendingOrder($me, 'pending', now()->addMinutes(20));

    vvT34Delete($code)->assertStatus(409);

    Storage::disk('uploads')->assertExists($file);
});
