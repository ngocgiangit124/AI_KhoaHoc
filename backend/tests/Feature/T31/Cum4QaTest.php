<?php

use App\Support\ProductionConfigGuard;
use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\TranscodeService;
use Illuminate\Support\Facades\Queue;

/**
 * QA cụm 4 (bổ sung ngoài Cum4ConfigTest): ca biên của videolab:notify, markFailed không đẩy queue, guard với tab/khoảng
 * trắng trong giá trị, CSP trên mọi mã trạng thái (401, 422, OPTIONS).
 */
function c4qaProductionConfig(): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'cache.limiter' => 'redis-limiter', 'captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net', 'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [], 'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
        'internal.required' => false, 'internal.ssr_token' => null, 'features.paid_checkout' => false,
        'videolab.enabled' => false,
    ]);
}

test('C4-M1: videolab:notify --limit chi bao dung so luong, theo thu tu id, lan sau bao not phan con lai', function () {
    Queue::fake();
    $videos = Video::factory()->finished()->count(5)->create();

    test()->artisan('videolab:notify', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(SendVideoLabWebhookJob::class, 2);
    $ordered = $videos->sortBy('id')->values();
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $ordered[0]->guid);
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $ordered[1]->guid);
    expect(Video::query()->whereNull('notified_at')->count())->toBe(3);

    test()->artisan('videolab:notify', ['--limit' => 10])->assertSuccessful();
    Queue::assertPushed(SendVideoLabWebhookJob::class, 5);
    expect(Video::query()->whereNull('notified_at')->count())->toBe(0);
});

test('C4-M1: videolab:notify khong bao video dang upload/xu ly/transcode va video upload loi', function (int $status) {
    Queue::fake();
    $video = Video::factory()->create(['status' => $status, 'notified_at' => null]);

    test()->artisan('videolab:notify')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($video->fresh()->notified_at)->toBeNull();
})->with([Video::CREATED, Video::UPLOADED, Video::PROCESSING, Video::TRANSCODING, Video::UPLOAD_FAILED]);

test('C4-M1: markFailed dat notified_at=null va KHONG day job vao queue; video da xong khong bi ghi de', function () {
    Queue::fake();
    $processing = Video::factory()->create(['status' => Video::TRANSCODING, 'notified_at' => now()]);
    $finished = Video::factory()->finished()->create(['notified_at' => now()]);

    app(TranscodeService::class)->markFailed($processing->guid, 'loi gia lap');
    app(TranscodeService::class)->markFailed($finished->guid, 'loi gia lap');

    Queue::assertNothingPushed();
    expect($processing->fresh()->status)->toBe(Video::ERROR)
        ->and($processing->fresh()->notified_at)->toBeNull()
        ->and($finished->fresh()->status)->toBe(Video::FINISHED)
        ->and($finished->fresh()->notified_at)->not->toBeNull();

    test()->artisan('videolab:notify')->assertSuccessful();
    Queue::assertPushed(SendVideoLabWebhookJob::class, 1);
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $processing->guid);
});

test('C4-M2: gia tri co tab + # hoac tab o cuoi bi chan; mat khau co dau cach giua (khong #) hop le', function (string $value, bool $blocked) {
    c4qaProductionConfig();
    $_ENV['DB_PASSWORD'] = $value;

    try {
        $blocked
            ? expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'DB_PASSWORD')
            : expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
    } finally {
        unset($_ENV['DB_PASSWORD']);
    }
})->with([
    ["abc\t# ghi chu", true],
    ["abc\t", true],
    ["\tabc", true],
    ["abc\n", true],
    ['mat khau co dau cach', false],
    ['mật-khẩu-tiếng-việt#1', false],
]);

test('C4-M2/L4: APP_DEBUG viet "false # x" (config la chuoi truthy) bi chan, khong lo trang debug', function () {
    c4qaProductionConfig();
    config(['app.debug' => 'false # moi truong']);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'APP_DEBUG');
    expect(config('app.debug'))->toBeFalse();
});

test('C4-L2: CSP + Permissions-Policy co tren 401 (api) va 403 (admin-api, Origin la)', function (string $hostKey, string $method, string $path, int $status) {
    $host = 'http://'.config($hostKey);
    $response = $this->call($method, $host.$path, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://evil.example']);

    expect($response->getStatusCode())->toBe($status);
    expect($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
})->with([
    'api 401' => ['app.api_host', 'GET', '/api/v1/auth/me', 401],
    'admin Origin la -> 403 (admin.origin)' => ['app.admin_api_host', 'GET', '/api/v1/admin/auth/me', 403],
]);
