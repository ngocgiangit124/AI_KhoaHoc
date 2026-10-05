<?php

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Auth\Otp\LogSmsOtpSender;
use App\Services\Auth\Otp\OtpDispatcher;
use App\Services\Auth\Otp\OtpSender;
use App\Services\Auth\Otp\OtpService;
use App\Services\Auth\Otp\SmsOtpSender;
use App\Support\ProductionConfigGuard;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

test('S21 OtpMail ShouldBeEncrypted: payload trong bang jobs la ciphertext (khong lo ma/email/ten)', function () {
    config(['queue.default' => 'database']);
    $user = User::factory()->create(['name' => 'Nguyen Van Bi Mat', 'email' => 'bi-mat@example.com']);

    app(OtpDispatcher::class)->send($user, OtpPurpose::VerifyAccount, 'email', 'bi-mat@example.com', '482913');

    $raw = (string) DB::table('jobs')->value('payload');
    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('482913')
        ->and($raw)->not->toContain('bi-mat@example.com')
        ->and($raw)->not->toContain('Nguyen Van Bi Mat');

    // Có giải mã được bằng APP_KEY thì mới ra mã (chứng minh payload thật sự được mã hoá, không bị bỏ).
    $payload = json_decode($raw, true);
    expect($payload['data']['command'])->not->toContain('SendQueuedMailable');
    $command = unserialize(decrypt($payload['data']['command']));
    expect($command->mailable->code)->toBe('482913');
});

test('OtpMail implements ShouldBeEncrypted + ShouldQueue', function () {
    expect(new OtpMail('A', '123456', OtpPurpose::VerifyAccount, 10))
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->toBeInstanceOf(ShouldQueue::class);
});

test('email OTP co ma, han dung va khong chua duong dan/PII thua', function () {
    $mail = new OtpMail('Lan', '654321', OtpPurpose::VerifyAccount, 10);

    $mail->assertSeeInHtml('654321');
    $mail->assertSeeInHtml('10 phút');
    expect($mail->envelope()->subject)->toBe('Mã xác thực VitaminVui của bạn');
});

test('S9 LogSmsOtpSender chi ghi *** (khong ghi ma, khong ghi SDT day du)', function () {
    $spy = Log::spy();

    (new LogSmsOtpSender)->send('0912345678', '918273');

    $spy->shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context = []) {
        $all = $message.json_encode($context);

        return ($context['code'] ?? null) === '***'
            && ! str_contains($all, '918273')
            && ! str_contains($all, '0912345678');
    });
});

test('S9 LogSmsOtpSender chi duoc bind o local/testing', function () {
    // Trong testing: có bind.
    expect(app(SmsOtpSender::class))->toBeInstanceOf(LogSmsOtpSender::class);

    // Mô phỏng production: bỏ binding rồi chạy lại register() với env production.
    $original = app()->environment();
    unset(app()[SmsOtpSender::class]);
    app()->detectEnvironment(fn () => 'production');
    (new AppServiceProvider(app()))->register();

    expect(app()->bound(SmsOtpSender::class))->toBeFalse()
        ->and(app()->bound(OtpSender::class))->toBeTrue();
    app()->detectEnvironment(fn () => $original);
});

test('S9 production: kenh sms duoc bat trong cau hinh -> app khong boot (guard)', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false,
        'session.secure' => true,
        'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn'],
        'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [],
        'auth.otp.channels' => ['email'],
    ]);

    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);

    config(['auth.otp.channels' => ['email', 'SMS']]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'sms');
});

test('S9 production (email only): channel=sms -> 422, khong phat ma nao', function () {
    config(['auth.otp.channels' => ['email']]);
    vvFakeOtp();
    vvOtpStudent();

    vvOtpSend(['channel' => 'sms'])->assertStatus(422)->assertJsonValidationErrors('channel');
    expect(OtpCode::count())->toBe(0);
});

test('kenh sms khi khong co SmsOtpSender (production) -> loi ro rang, khong ghi ma o dau ca', function () {
    unset(app()[SmsOtpSender::class]);
    $user = User::factory()->create();

    expect(fn () => app(OtpDispatcher::class)->send($user, OtpPurpose::VerifyAccount, 'sms', $user->phone, '123456'))
        ->toThrow(RuntimeException::class, 'SMS');
});

test('S21 grep log sau luong dang ky/gui/verify khong co ma OTP 6 so, khong co SDT', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'vv-otp-log-');
    config(['logging.channels.otp_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('otp_test');
    config(['auth.otp.channels' => ['email', 'sms']]);

    Mail::fake();

    // Đăng ký → OTP email.
    vvFollowSession(vvRegister(['email' => 'log@example.com', 'phone' => '0912345678'])->assertCreated());
    $emailCode = Mail::queued(OtpMail::class)->first()->code;
    expect($emailCode)->toMatch('/^\d{6}$/');

    // Sai rồi đúng, qua HTTP.
    vvOtpVerify($emailCode === '000000' ? '111111' : '000000')->assertStatus(422);
    vvOtpVerify($emailCode)->assertOk();

    // Kênh SMS thật (LogSmsOtpSender), mã không biết trước nhưng phải không xuất hiện trong log.
    $sms = User::factory()->create(['phone' => '0988777666']);
    app(OtpService::class)->sendVerification($sms, 'sms');

    // Đổi liên hệ.
    vvActAsStudent(User::query()->where('email', 'log@example.com')->firstOrFail());
    vvContactUpdate(['email' => 'log2@example.com'])->assertOk();

    $log = (string) file_get_contents($logFile);
    @unlink($logFile);

    expect($log)->toContain('***')                              // dòng SMS giả lập có mặt
        ->and($log)->not->toContain($emailCode)
        ->and($log)->not->toContain('0988777666')
        ->and($log)->not->toContain('0912345678')
        ->and($log)->not->toContain('log@example.com');
    // Không có chuỗi 6 chữ số đứng riêng (loại timestamp/uuid/id có dấu - : . bao quanh).
    expect(preg_match('/(?<![\w.:\-])\d{6}(?![\w.:\-])/', $log))->toBe(0);
});

test('exception ghi log khi gui OTP loi khong chua ma', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'vv-otp-log-');
    config(['logging.channels.otp_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('otp_test');

    $captured = null;
    app()->instance(OtpSender::class, new class($captured) implements OtpSender
    {
        public function __construct(public mixed &$code) {}

        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            $this->code = $code;
            throw new RuntimeException('smtp down');
        }
    });

    vvRegister()->assertCreated();

    $log = (string) file_get_contents($logFile);
    @unlink($logFile);

    expect($captured)->toMatch('/^\d{6}$/')->and($log)->not->toContain($captured);
});

test('R2 gui ma that bai -> 503 OTP_DELIVERY_FAILED, xoa ma vua tao, khong tinh cooldown/tran, gui lai duoc ngay', function () {
    app()->instance(OtpSender::class, new class implements OtpSender
    {
        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            throw new RuntimeException('smtp down');
        }
    });
    $user = vvOtpStudent();

    foreach (range(1, 3) as $i) {
        // Gọi service (không qua throttle middleware) để chứng minh trần DB không bị tiêu hao.
        try {
            app(OtpService::class)->sendVerification($user, 'email');
            $this->fail('Phải ném OTP_DELIVERY_FAILED');
        } catch (DomainException $e) {
            expect($e->code())->toBe('OTP_DELIVERY_FAILED')->and($e->status())->toBe(503);
        }
    }

    expect(OtpCode::count())->toBe(0);

    $sender = vvFakeOtp();
    vvOtpSend()->assertStatus(202);
    expect($sender->sent)->toHaveCount(1);
});

test('R2 endpoint tra 503 envelope OTP_DELIVERY_FAILED khi Mail/queue loi', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));
    vvOtpStudent();

    vvOtpSend()->assertStatus(503)->assertJson(['code' => 'OTP_DELIVERY_FAILED']);
    expect(OtpCode::count())->toBe(0);
});

test('R2 log ghi nguyen nhan that nhung che ma 6 so, khong co stack trace chua ma', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'vv-otp-log-');
    config(['logging.channels.otp_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('otp_test');

    $captured = null;
    app()->instance(OtpSender::class, new class($captured) implements OtpSender
    {
        public function __construct(public mixed &$code) {}

        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            $this->code = $code;
            throw new RuntimeException("550 rejected body={$code}");
        }
    });

    vvOtpStudent();
    vvOtpSend()->assertStatus(503);

    $log = (string) file_get_contents($logFile);
    @unlink($logFile);

    expect($log)->toContain('RuntimeException')->toContain('550 rejected body=******')
        ->and($log)->not->toContain($captured);
});

test('R3 doi lien he xen giua luc verify (sau khi tang attempts) -> khong xac thuc email moi, ma khong bi consume', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent();
    vvOtpSend();
    $code = $sender->lastCode();
    $fired = false;

    DB::listen(function ($query) use (&$fired, $user) {
        if (! $fired && str_contains($query->sql, 'update `otp_codes` set `attempts`')) {
            $fired = true;
            // Request khác đổi email đúng khe giữa "tăng attempts" và "consume".
            DB::table('users')->where('id', $user->id)->update(['email' => 'khac@example.com']);
        }
    });

    vvOtpVerify($code)->assertStatus(422)->assertJsonValidationErrors('code');

    expect($fired)->toBeTrue();
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('khac@example.com')->and($fresh->email_verified_at)->toBeNull();
    expect(OtpCode::first()->consumed_at)->toBeNull();
});

test('R3 consume + ghi email_verified_at cung transaction: loi giua chung thi rollback ca hai', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent();
    vvOtpSend();

    expect(fn () => app(OtpService::class)->consume(
        $user,
        OtpPurpose::VerifyAccount,
        $sender->lastCode(),
        null,
        function () {
            throw new RuntimeException('loi giua chung');
        },
    ))->toThrow(RuntimeException::class);

    expect(OtpCode::first()->consumed_at)->toBeNull();
});

test('BUG-1 S21 moi duong loi verify (sai, het han, het luot, da xac thuc) khong de ma nguoi dung go lai trong log', function () {
    config(['auth.otp.max_verify_per_minute' => 100]);
    $logFile = tempnam(sys_get_temp_dir(), 'vv-otp-log-');
    config(['logging.channels.otp_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Log::setDefaultDriver('otp_test');

    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend();
    $right = $sender->lastCode();

    foreach (['314159', '271828', '161803', '141421', '173205'] as $typed) {
        vvOtpVerify($typed === $right ? '999999' : $typed)->assertStatus(422);
    }
    vvOtpVerify($right)->assertStatus(429);   // hết lượt
    $this->travel(11)->minutes();
    vvOtpVerify('112358')->assertStatus(422); // hết hạn

    $log = (string) file_get_contents($logFile);
    @unlink($logFile);

    foreach (['314159', '271828', '161803', '141421', '173205', '112358', $right] as $typed) {
        expect($log)->not->toContain($typed);
    }
});

test('BUG-3 verify khi tai khoan da xac thuc -> 422 thong diep "da xac thuc", khong phai het han', function () {
    vvOtpStudent(['email_verified_at' => now()]);

    vvOtpVerify('123456')->assertStatus(422)
        ->assertJson(['errors' => ['code' => [OtpService::MESSAGE_ALREADY_VERIFIED]]]);
});

test('BUG-2 CORS expose Retry-After de trinh duyet doc duoc cooldown', function () {
    expect(config('cors.exposed_headers'))->toContain('Retry-After')->toContain('X-Request-Id');
});
