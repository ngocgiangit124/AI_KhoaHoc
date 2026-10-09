<?php

use App\Models\User;
use App\Services\Privacy\ParentNoticeToken;
use App\Services\Privacy\ParentNotifier;
use App\Support\Mask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../T29/helpers.php';

test('QA S4: Mask::emailsInText - cac dang email la va khong phai email', function (string $in, bool $hasEmail) {
    $out = Mask::emailsInText($in);
    expect($out)->not->toContain('@');
    if ($hasEmail) {
        expect($out)->toContain('***');
    } else {
        expect($out)->toBe($in);
    }
})->with([
    'tien to + va dau cham' => ['first.last+tag@sub.example.co.uk', true],
    'chu hoa' => ['USER@EXAMPLE.COM', true],
    'trong ngoac nhon' => ['<a@b.c>: rejected', true],
    'trong ngoac kep thong diep' => ['got "550 <x@y.vn>" end', true],
    'dau gach duoi/ngang' => ['a_b-c@d-e.f', true],
    'IDN tieng Viet' => ['phụ.huynh@trường.vn', true],
    'xuong dong giua van ban' => ["dong1\nx@y.vn\ndong3", true],
    'nhieu email lien tiep' => ['a@b.c,d@e.f;g@h.i', true],
    'dia chi IP' => ['u@[10.0.0.1]', true],
    'quoted local co khoang trang' => ['"a b"@x.com', true],
    'email lap lai' => ['a@b.c a@b.c a@b.c', true],
    'chi co @ le loi' => ['@', true],
    'chuoi rong' => ['', false],
    'khong co @' => ['Connection refused (tcp://127.0.0.1:25)', false],
]);

test('QA S4: Mask::emailsInText UTF-8 hong khong lam lo - tra *** thay vi email', function () {
    $out = Mask::emailsInText("x\xC3\x28 bad@ex.com");
    expect($out)->not->toContain('@')->not->toContain('bad');
});

test('QA S6: tran 500/gio - 500 thu dau gui, thu 501 bi chan; log warning global_cap_reached 1 lan moi thu bi chan', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 3]);
    RateLimiter::clear('parent-notice:global');
    Log::spy();

    foreach (range(1, 5) as $i) {
        app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => "qa{$i}@example.com"]));
    }

    expect(vvT29Notices())->toHaveCount(3);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'parent_notice.global_cap_reached' && ($c['cap'] ?? null) === 3)->twice();
});

test('QA S6: cap am / chuoi rong / 0 -> khong gui; thu bi chan chi log, khong nem loi cho request goc', function (mixed $cap) {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => $cap]);
    RateLimiter::clear('parent-notice:global');
    $key = 'parent-notice:'.ParentNoticeToken::addressHash('capx@example.com');
    RateLimiter::clear($key);
    Log::spy();

    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'capx@example.com']));

    expect(vvT29Notices())->toHaveCount(0)->and(RateLimiter::attempts($key))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'parent_notice.global_cap_reached')->once();
})->with([0, -5, '', 'abc', null]);

test('QA S6: thu bi chan boi tran DIA CHI khong ton luot tran tong; thu da huy nhan/anonymized khong ton', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 2, 'privacy.parent_notice_daily_cap_per_address' => 1]);
    RateLimiter::clear('parent-notice:global');
    RateLimiter::clear('parent-notice:'.ParentNoticeToken::addressHash('same@example.com'));

    foreach (range(1, 3) as $i) {
        app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'same@example.com']));
    }
    expect(vvT29Notices())->toHaveCount(1);
    // Trần tổng mới dùng 1/2: thư của địa chỉ khác vẫn đi được.
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'other@example.com']));
    expect(vvT29Notices())->toHaveCount(2);
});

test('QA S6: tran tong khong dung chung giua gio cu va moi (RateLimiter het han) - clear -> gui lai', function () {
    Mail::fake();
    config(['privacy.parent_notice_global_hourly_cap' => 1]);
    RateLimiter::clear('parent-notice:global');
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'h1@example.com']));
    app(ParentNotifier::class)->accountCreated(User::factory()->student()->verified()->create(['parent_email' => 'h2@example.com']));
    expect(vvT29Notices())->toHaveCount(1)->and(RateLimiter::availableIn('parent-notice:global'))->toBeLessThanOrEqual(3600)->toBeGreaterThan(0);
});

/**
 * Worker queue THẬT: tiến trình `php artisan queue:work --once` (container php, DB test h, queue `database`) + SMTP giả từ chối
 * người nhận và nhắc lại địa chỉ trong thông điệp. Không dùng container `queue` (trỏ DB dev).
 */
function vvBeb1QaEnv(int $port = 0): array
{
    return [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'CACHE_LIMITER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'smtp', 'MAIL_SCHEME' => 'null', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port,
        'MAIL_USERNAME' => 'null', 'MAIL_PASSWORD' => 'null', 'LOG_CHANNEL' => 'stderr', 'LOG_STACK' => 'stderr',
        'FEATURE_PARENT_NOTICES' => 'true', 'PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP' => '500',
    ];
}

function vvBeb1QaRun(array $cmd, array $env, int $timeout = 90): Process
{
    $p = new Process([PHP_BINARY, ...$cmd], base_path(), $env);
    $p->setTimeout($timeout);
    $p->run();

    return $p;
}

test('QA S4 WORKER THAT: SMTP tu choi nguoi nhan (thong diep co email) -> failed_jobs.exception va log khong co dia chi; retry dung tries=3', function () {
    $email = 'phu.huynh.bi.mat@example.com';
    $port = random_int(20000, 40000);
    $smtp = new Process([PHP_BINARY, base_path('tests/Support/qa_fake_smtp.php'), (string) $port]);
    $smtp->start();
    usleep(500000);
    $env = vvBeb1QaEnv($port);
    $driver = base_path('tests/Support/qa_parent_notice_driver.php');
    $userId = null;

    try {
        expect($smtp->isRunning())->toBeTrue();
        $enq = vvBeb1QaRun([$driver, 'enqueue', $email], $env);
        expect($enq->getExitCode())->toBe(0, $enq->getErrorOutput().$enq->getOutput());
        $info = json_decode(trim($enq->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $userId = $info['user'];
        expect($info['jobs'])->toBe(1, $enq->getOutput().$enq->getErrorOutput());

        $logs = '';
        $attemptsSeen = [];
        for ($i = 1; $i <= 3; $i++) {
            $w = vvBeb1QaRun(['artisan', 'queue:work', 'database', '--once', '--queue=default', '--sleep=0'], $env);
            $logs .= $w->getOutput().$w->getErrorOutput();
            $rel = json_decode(trim(vvBeb1QaRun([$driver, 'release'], $env)->getOutput()), true);
            $attemptsSeen[] = $rel['attempts'];
        }
        $state = json_decode(trim(vvBeb1QaRun([$driver, 'state'], $env)->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        expect($attemptsSeen[0])->toBe(1)->and($attemptsSeen[1])->toBe(2)
            ->and($state['failed'])->toBe(1)->and($state['jobs'])->toBe(0)
            ->and($state['exception'])->toContain('UnexpectedResponseException')->toContain('***')
            ->not->toContain('@')->not->toContain($email)->not->toContain('phu.huynh');
        expect($logs)->not->toContain($email)->not->toContain('phu.huynh.bi.mat')->not->toContain('@'.'example.com');
        expect($logs)->toContain('parent_notice.mail_failed');
    } finally {
        $smtp->stop();
        if ($userId) {
            vvBeb1QaRun([$driver, 'cleanup', (string) $userId], $env);
        }
    }
})->group('worker');
