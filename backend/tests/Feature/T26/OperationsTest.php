<?php

use App\Jobs\SyncVideoAssetStatusJob;
use App\Mail\EnrollmentDecisionMail;
use App\Mail\OtpMail;
use App\Mail\StaffNewDeviceMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use App\Support\Heartbeat;
use App\Support\ProductionConfigGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

function vvT26CatalogGet(array $headers = [], string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('http://api.localhost/api/v1/courses', $headers);
}

beforeEach(function () {
    config(['internal.ssr_token' => str_repeat('a', 40), 'internal.catalog_per_minute' => 3, 'internal.catalog_ssr_total_per_minute' => 100]);
    RateLimiter::clear('x');
    Cache::flush();
});

test('khong token: moi nguoi chung bucket theo IP ket noi, X-Client-IP bi bo qua', function () {
    foreach ([1, 2, 3] as $i) {
        vvT26CatalogGet(['X-Client-IP' => "1.1.1.$i"])->assertOk();
    }
    vvT26CatalogGet(['X-Client-IP' => '9.9.9.9'])->assertStatus(429);
});

test('token sai: nhu khong token', function () {
    foreach ([1, 2, 3] as $i) {
        vvT26CatalogGet(['X-Internal-Token' => 'sai', 'X-Client-IP' => "1.1.1.$i"])->assertOk();
    }
    vvT26CatalogGet(['X-Internal-Token' => 'sai', 'X-Client-IP' => '9.9.9.9'])->assertStatus(429);
});

test('token dung: bucket theo IP khach; khach A bi chan khong anh huong khach B', function () {
    $h = fn (string $ip) => ['X-Internal-Token' => str_repeat('a', 40), 'X-Client-IP' => $ip];

    foreach ([1, 2, 3] as $_) {
        vvT26CatalogGet($h('2.2.2.2'))->assertOk();
    }
    vvT26CatalogGet($h('2.2.2.2'))->assertStatus(429);
    vvT26CatalogGet($h('3.3.3.3'))->assertOk();
});

test('token dung nhung tran tong SSR van ap dung', function () {
    config(['internal.catalog_ssr_total_per_minute' => 2]);
    $h = fn (string $ip) => ['X-Internal-Token' => str_repeat('a', 40), 'X-Client-IP' => $ip];

    vvT26CatalogGet($h('4.4.4.1'))->assertOk();
    vvT26CatalogGet($h('4.4.4.2'))->assertOk();
    vvT26CatalogGet($h('4.4.4.3'))->assertStatus(429);
});

test('token chua cau hinh thi tat', function () {
    config(['internal.ssr_token' => null]);
    RateLimiter::clear('x');
    Cache::flush();
    foreach ([1, 2, 3] as $_) {
        vvT26CatalogGet(['X-Internal-Token' => '', 'X-Client-IP' => '5.5.5.5'], '10.0.0.9')->assertOk();
    }
    vvT26CatalogGet(['X-Internal-Token' => '', 'X-Client-IP' => '6.6.6.6'], '10.0.0.9')->assertStatus(429);
});

test('ProductionConfigGuard: token qua ngan bi chan, de trong hoac du dai thi qua', function () {
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'cache.limiter' => 'redis-limiter', 'captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app',
        'internal.ssr_token' => 'ngan',
    ]);
    app()->detectEnvironment(fn () => 'production');

    $guard = new ReflectionMethod(ProductionConfigGuard::class, 'guardInternalToken');
    expect(fn () => $guard->invoke(new ProductionConfigGuard))->toThrow(RuntimeException::class, 'INTERNAL_API_TOKEN');
    config(['internal.ssr_token' => '']);
    expect($guard->invoke(new ProductionConfigGuard))->toBeNull();
    config(['internal.ssr_token' => str_repeat('b', 32)]);
    expect($guard->invoke(new ProductionConfigGuard))->toBeNull();
});

test('lich: du lenh, moi lenh withoutOverlapping + onOneServer', function () {
    $events = collect(app(Schedule::class)->events());
    $cmds = $events->map(fn ($e) => $e->command)->implode("\n");

    foreach (['counters:recount', 'videos:check-stuck', 'videos:prune-orphans', 'otp:prune', 'queue:prune-failed', 'queue:monitor', 'ops:health'] as $c) {
        expect($cmds)->toContain($c);
    }

    $commands = $events->filter(fn ($e) => $e->command !== null);
    expect($commands)->not->toBeEmpty();
    foreach ($commands as $e) {
        expect($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
    }
});

test('job va mailable khai bao tries/timeout/backoff, timeout < retry_after', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    foreach ([SyncVideoAssetStatusJob::class, OtpMail::class, EnrollmentDecisionMail::class, StaffNewDeviceMail::class] as $class) {
        $r = new ReflectionClass($class);
        foreach (['tries', 'timeout'] as $p) {
            expect($r->hasProperty($p))->toBeTrue("$class::\$$p");
        }
        expect($r->getDefaultProperties()['timeout'])->toBeLessThan($retryAfter);
        expect($r->hasProperty('backoff') || $r->hasMethod('backoff'))->toBeTrue();
    }
});

test('ops:health: thieu nhip -> exit 1; du nhip -> 0; failed_jobs ton dong -> 1', function () {
    expect(Artisan::call('ops:health'))->toBe(1);

    Heartbeat::beat('worker');
    Heartbeat::beat('scheduler');
    expect(Artisan::call('ops:health', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['ok'])->toBeTrue();

    config(['ops.health.failed_jobs_max' => 0]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    expect(Artisan::call('ops:health'))->toBe(1);
});

test('otp:prune: chi xoa ma cu da het han', function () {
    $user = User::factory()->create();
    $mk = fn (string $created, string $expires) => OtpCode::factory()->create(['user_id' => $user->id, 'created_at' => $created, 'expires_at' => $expires]);
    $old = $mk(now()->subDays(10), now()->subDays(10));
    $recent = $mk(now()->subDay(), now()->subDay());
    $fresh = $mk(now(), now()->addMinutes(10));

    Artisan::call('otp:prune', ['--dry-run' => true]);
    expect(OtpCode::count())->toBe(3);

    Artisan::call('otp:prune');
    expect(OtpCode::pluck('id')->all())->toEqualCanonicalizing([$recent->id, $fresh->id])->and(OtpCode::find($old->id))->toBeNull();
});

test('ProductionConfigGuard: INTERNAL_API_REQUIRED + token rong -> nem loi', function () {
    $guard = new ReflectionMethod(ProductionConfigGuard::class, 'guardInternalToken');
    config(['internal.required' => true, 'internal.ssr_token' => '']);
    expect(fn () => $guard->invoke(new ProductionConfigGuard))->toThrow(RuntimeException::class, 'INTERNAL_API_REQUIRED');

    config(['internal.ssr_token' => str_repeat('c', 32)]);
    expect($guard->invoke(new ProductionConfigGuard))->toBeNull();

    config(['internal.required' => false, 'internal.ssr_token' => '']);
    expect($guard->invoke(new ProductionConfigGuard))->toBeNull();
});

test('Heartbeat::beat khong nem loi khi cache hong', function () {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));

    expect(fn () => Heartbeat::beat('worker'))->not->toThrow(Throwable::class);
});

test('otp:prune khong xoa ban ghi trong 24h (ke ca da het han)', function () {
    $user = User::factory()->create();
    $recent = OtpCode::factory()->create(['user_id' => $user->id, 'created_at' => now()->subHours(23), 'expires_at' => now()->subHours(22)]);

    Artisan::call('otp:prune');

    expect(OtpCode::find($recent->id))->not->toBeNull();
});

test('lich: khong dang ky trung va thoi han khoa du dai', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->command !== null);
    $names = $events->map(fn ($e) => $e->command)->all();

    expect($names)->toHaveCount(count(array_unique($names)));
    expect($events->filter(fn ($e) => str_contains($e->command, 'videos:')))->toHaveCount(2);

    $expires = fn (string $c) => $events->first(fn ($e) => str_contains($e->command, $c))->expiresAt;
    expect($expires('counters:recount'))->toBeGreaterThanOrEqual(180)->and($expires('videos:prune-orphans'))->toBeGreaterThanOrEqual(120);
});

test('otp:prune roi gui lai OTP: bo dem 24h khong lech (van chan khi du tran ngay)', function () {
    require_once __DIR__.'/../T04/helpers.php';
    config(['auth.otp.max_per_hour' => 1000, 'auth.otp.max_per_day' => 3]);
    vvFakeOtp();
    $user = User::factory()->create();
    // 3 ma trong 24h (da het han) + 5 ma cu 10 ngay
    foreach ([1, 2, 3] as $h) {
        OtpCode::factory()->create(['user_id' => $user->id, 'created_at' => now()->subHours($h), 'expires_at' => now()->subHours($h)->addMinutes(10)]);
    }
    foreach (range(1, 5) as $_) {
        OtpCode::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(10), 'expires_at' => now()->subDays(10)->addMinutes(10)]);
    }

    Artisan::call('otp:prune');
    expect(OtpCode::count())->toBe(3);

    expect(fn () => app(OtpService::class)->sendVerification($user, 'email', false))
        ->toThrow(ThrottleRequestsException::class);
    expect(OtpCode::count())->toBe(3);
});

test('ops:health: nhip cu hon nguong -> exit 1 (worker), scheduler cu -> exit 1', function () {
    $p = config('ops.health.cache_prefix');
    Cache::put($p.'worker', time() - 500, 3600);
    Cache::put($p.'scheduler', time(), 3600);
    expect(Artisan::call('ops:health', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['worker_age'])->toBeGreaterThan(120);

    Cache::put($p.'worker', time(), 3600);
    Cache::put($p.'scheduler', time() - 500, 3600);
    expect(Artisan::call('ops:health'))->toBe(1);

    Cache::put($p.'scheduler', time(), 3600);
    expect(Artisan::call('ops:health'))->toBe(0);
});

test('Heartbeat::workerBeat khong nem loi khi cache hong', function () {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));
    $r = new ReflectionProperty(Heartbeat::class, 'lastWorkerWrite');
    $r->setValue(null, 0);

    expect(fn () => Heartbeat::workerBeat())->not->toThrow(Throwable::class);
});
