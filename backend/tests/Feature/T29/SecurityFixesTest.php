<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Privacy\ParentNoticeSuppression;
use App\Services\Privacy\ParentNoticeToken;
use App\Services\Privacy\ParentNotifier;
use App\Support\ProductionConfigGuard;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

test('S1: ten chua so dien thoai/cau quang cao dai -> HTML, text, subject khong co chu so; toi da 4 tu', function () {
    $student = User::factory()->student()->verified()->create([
        'name' => 'Nhận học bổng 5 triệu gọi Zalo 0912345678 trước 20h hôm nay x',
        'parent_email' => 'ph@example.com',
    ]);

    app(ParentNotifier::class)->accountCreated($student);

    $mail = vvT29Notices()->first();
    expect($mail->maskedStudentName)->toBe('Nhận học bổng triệu Gọ**'.'' === '' ? '' : $mail->maskedStudentName);
    expect(count(explode(' ', $mail->maskedStudentName)))->toBeLessThanOrEqual(4);

    $html = $mail->render();
    $text = view('emails.parent-notice-text', $mail->buildViewData())->render();
    $subject = $mail->envelope()->subject;

    // Bỏ phần cố định hợp lệ (URL có token chứa số) trước khi kiểm chữ số trong nội dung tên.
    expect($mail->maskedStudentName)->not->toMatch('/\d/')
        ->and($subject)->not->toMatch('/\d/')->not->toContain($mail->maskedStudentName)
        ->and($html)->not->toContain('0912345678')->and($text)->not->toContain('0912345678')
        ->and($html)->not->toContain('Zalo')->and($subject)->not->toContain('Nhận');
});

test('S1: ten khoa hoc trong thu lay tu bang courses (khong tu snapshot do nguoi dung)', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student, 100000, 'Toán 9 nâng cao');
    DB::table('order_items')->where('order_id', $order->id)->update(['course_title' => 'Gọi 0912345678']);

    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    expect(vvT29Notices()->first()->courseTitles)->toBe(['Toán 9 nâng cao']);
});

test('S2: guard production bat buoc khoa rieng >= 32 byte va tran thu 1..20', function (mixed $key, mixed $cap, bool $ok) {
    $guard = new ProductionConfigGuard;
    $method = new ReflectionMethod($guard, 'guardParentNotices');

    config(['privacy.notice_token_key' => $key, 'privacy.parent_notice_daily_cap_per_address' => $cap]);

    if ($ok) {
        $method->invoke($guard);
        expect(true)->toBeTrue();
    } else {
        expect(fn () => $method->invoke($guard))->toThrow(RuntimeException::class);
    }
})->with([
    'hop le' => [str_repeat('k', 32), 5, true],
    'khoa rong' => ['', 5, false],
    'khoa null' => [null, 5, false],
    'khoa ngan' => [str_repeat('k', 31), 5, false],
    'cap 0' => [str_repeat('k', 32), 0, false],
    'cap am' => [str_repeat('k', 32), -1, false],
    'cap 21' => [str_repeat('k', 32), 21, false],
    'cap 20' => [str_repeat('k', 32), 20, true],
]);

test('S2: cap <= 0 hoac > 20 -> fail-closed, khong gui thu', function (int $cap) {
    config(['privacy.parent_notice_daily_cap_per_address' => $cap]);
    $student = User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']);

    app(ParentNotifier::class)->accountCreated($student);

    expect(vvT29Notices())->toHaveCount(0);
})->with([0, -3, 21]);

test('S2: doi app.key khi da co khoa rieng -> dia chi da huy nhan van bi chan, token cu van hop le', function () {
    config(['privacy.notice_token_key' => str_repeat('s', 40)]);
    $student = User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']);
    $token = vvT29Token($student);
    test()->postJson(vvT29UnsubUrl(), ['token' => $token], ['Origin' => config('app.frontend_url')])->assertOk();

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(app(ParentNoticeSuppression::class)->isSuppressed('ph@example.com'))->toBeTrue()
        ->and(ParentNoticeToken::verify($token))->not->toBeNull();

    $other = User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']);
    app(ParentNotifier::class)->accountCreated($other);
    expect(vvT29Notices())->toHaveCount(0);
});

test('S3: verify -> doi email -> verify lai -> chi 1 thu account_created trong doi tai khoan', function () {
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => 'ph@example.com'])->assertCreated();
    $user = User::firstOrFail();
    $service = app(OtpService::class);

    $service->verifyAccount($user, $otp->lastCode());
    expect(vvT29Notices('account_created'))->toHaveCount(1);

    // doi email: reset xac thuc, gui OTP moi, xac thuc lai
    $user->refresh();
    $user->forceFill(['email' => 'moi@example.com', 'email_verified_at' => null, 'phone_verified_at' => null])->save();
    $service->sendVerification($user->fresh(), 'email', false);
    $service->verifyAccount($user->fresh(), $otp->lastCode());

    expect($user->fresh()->isVerified())->toBeTrue()
        ->and(vvT29Notices('account_created'))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'account.verified')->where('subject_id', $user->id)->count())->toBe(2);
});
