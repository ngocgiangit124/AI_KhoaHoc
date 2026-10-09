<?php

use App\Mail\ParentNoticeMail;
use App\Models\User;
use App\Services\Privacy\ParentNoticeToken;
use App\Services\Privacy\ParentNotifier;
use App\Support\Mask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/../T29/helpers.php';

test('T29-S4: Mask::emailsInText che moi dia chi email trong van ban loi SMTP', function (string $in) {
    expect(Mask::emailsInText($in))->not->toContain('@')->and(Mask::emailsInText($in))->toContain('***')->not->toContain('brien')->not->toContain('user');
})->with([
    'Email "ph.huynh+x@example.com" does not comply with addr-spec of RFC 2822.',
    'Expected response code "250" but got code "550", with message "550 5.1.1 <ph@mail.example.vn>: Recipient address rejected".',
    'Connection to ph@example.com:25 timed out',
    'Địa chỉ phụ-huynh@trường.edu.vn bị từ chối',
    "Recipient o'brien@x.com rejected",
    'Recipient "a b"@x.com rejected',
    'Host user@[192.168.0.1] unreachable',
    'multi a@b.vn,c@d.vn;e@f.vn (g@h.vn)',
]);

test('T29-S4: transport nem loi chua email -> exception thoat khoi mailable (failed_jobs/log) khong co "@" va khong co previous', function () {
    $student = User::factory()->student()->verified()->create(['parent_email' => 'bi.mat@example.com']);
    Mail::fake();
    app(ParentNotifier::class)->accountCreated($student->fresh());
    /** @var ParentNoticeMail $mail */
    $mail = vvT29Notices()->first();

    $transport = new class
    {
        public function send(...$args): void
        {
            throw new TransportException('Expected response code "250" but got "550" for "bi.mat@example.com": rejected <bi.mat@example.com>');
        }
    };

    try {
        $mail->send($transport);
        $this->fail('Phải ném lỗi');
    } catch (Throwable $e) {
        expect((string) $e)->not->toContain('@')->not->toContain('bi.mat')
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toContain('TransportException')->toContain('***');
    }

    Log::spy();
    $mail->failed($e);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => $m === 'parent_notice.mail_failed' && ! str_contains(json_encode($ctx), '@'))->once();
});

test('T29-S6: tran tong gui ben thu ba: vuot tran/gio thi bo thu + Log::warning, khong tinh thu bi loai truoc do', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 2]);
    RateLimiter::clear('parent-notice:global');
    Log::spy();

    foreach (range(1, 3) as $i) {
        app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => "ph{$i}@example.com"]));
    }

    expect(vvT29Notices())->toHaveCount(2);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => $m === 'parent_notice.global_cap_reached' && ! str_contains(json_encode($ctx), '@'))->once();
});

test('T29-S6: thu bi loai (khong co email phu huynh / da huy nhan) khong ton luot cua tran tong', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 1]);
    RateLimiter::clear('parent-notice:global');

    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => null]));
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'x@example.com', 'parent_notice_opt_out_at' => now()]));
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'y@example.com']));

    expect(vvT29Notices())->toHaveCount(1);
});

test('T29-S6: tran tong cau hinh sai (< 1) -> khong gui (fail-closed); mac dinh 500', function () {
    Mail::fake();
    expect(config('privacy.parent_notice_global_hourly_cap'))->toBe(500);

    config(['privacy.parent_notice_global_hourly_cap' => 0]);
    RateLimiter::clear('parent-notice:global');
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'z@example.com']));

    expect(vvT29Notices())->toHaveCount(0);
});

test('T29-S6 (R1): thu bi tran tong chan khong ton luot 5/ngay cua dia chi; het cua so thi dia chi van du luot', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 1, 'privacy.parent_notice_daily_cap_per_address' => 2]);
    RateLimiter::clear('parent-notice:global');
    $key = 'parent-notice:'.ParentNoticeToken::addressHash('phu@example.com');
    RateLimiter::clear($key);

    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'khac@example.com']));
    foreach (range(1, 3) as $i) {
        app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'phu@example.com']));
    }

    expect(vvT29Notices())->toHaveCount(1)->and(RateLimiter::attempts($key))->toBe(0);

    RateLimiter::clear('parent-notice:global');
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'phu@example.com']));
    expect(vvT29Notices())->toHaveCount(2)->and(RateLimiter::attempts($key))->toBe(1);
});
