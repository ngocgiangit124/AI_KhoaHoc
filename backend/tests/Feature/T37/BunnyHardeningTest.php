<?php

use App\Enums\VideoAssetStatus;
use App\Jobs\SyncVideoAssetStatusJob;
use App\Models\VideoAsset;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\Bunny\BunnySigner;
use App\Services\Video\VideoAssetSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

// ---- S1: webhook ----

test('S1: asset da ready/failed -> webhook 204 nhung KHONG goi Bunny', function (VideoAssetStatus $status) {
    bzConfigure();
    bzLessonWithAsset($status);
    Http::fake();

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    Http::assertNothingSent();
})->with([[VideoAssetStatus::Ready], [VideoAssetStatus::Failed]]);

test('S1: hai webhook lien tiep cho cung asset dang xu ly -> chi 1 loi goi Bunny dong bo', function () {
    bzConfigure();
    bzLessonWithAsset();
    Cache::flush();
    Queue::fake();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 3), 200)]);

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    Http::assertSentCount(1);
});

test('R-2: webhook bi gom -> xep job dong bo tre ~15s (unique); job chay thi asset ready, khong cho quet 15 phut', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Cache::flush();
    Queue::fake();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 3), 200)]);

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    Queue::assertNothingPushed(); // webhook dau xu ly ngay, khong can job

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    Queue::assertPushed(SyncVideoAssetStatusJob::class, function (SyncVideoAssetStatusJob $job) use ($asset) {
        $delay = $job->delay;

        return $job->videoAssetId === $asset->id && $job instanceof ShouldBeUnique
            && $delay instanceof DateTimeInterface && abs($delay->getTimestamp() - (time() + 15)) <= 3;
    });

    // Bunny bao Finished o lan sau: job chay -> ready.
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 42), 200)]);
    (new SyncVideoAssetStatusJob($asset->id))->handle(app(VideoAssetSyncService::class));

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($asset->fresh()->duration_seconds)->toBe(42);
});

test('R-2: asset da cuoi / sai token / guid la -> khong xep job', function () {
    bzConfigure();
    bzLessonWithAsset(VideoAssetStatus::Ready);
    Cache::flush();
    Queue::fake();
    Http::fake();

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    bzWebhook(['VideoGuid' => BZ_GUID], 'sai')->assertNotFound();
    bzWebhook(['VideoGuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'])->assertNoContent();

    Queue::assertNothingPushed();
});

test('S1: Bunny loi khi xac minh -> 503 va nha khoa de lan gui lai duoc xu ly', function () {
    bzConfigure();
    bzLessonWithAsset();
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response('x', 502)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertStatus(503);

    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 9), 200)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    expect(VideoAsset::query()->sole()->status)->toBe(VideoAssetStatus::Ready);
});

test('S1: thieu/sai bi mat ?k= -> 404, khong goi mang, khong doi du lieu', function (?string $token) {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Http::fake();

    bzWebhook(['VideoGuid' => BZ_GUID], $token)->assertNotFound();

    Http::assertNothingSent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
})->with([[null], ['sai'], [''], [BZ_WEBHOOK_TOKEN.'x'], [strtoupper(BZ_WEBHOOK_TOKEN)]]);

test('S1: chua cau hinh webhook_token -> dong an toan (404 ca khi gui token rong)', function () {
    bzConfigure(['webhook_token' => null]);
    bzLessonWithAsset();
    Http::fake();

    bzWebhook(['VideoGuid' => BZ_GUID], '')->assertNotFound();
    bzWebhook(['VideoGuid' => BZ_GUID], null)->assertNotFound();

    Http::assertNothingSent();
});

test('S1: k dang mang (?k[]=x) -> 404', function () {
    bzConfigure();
    Http::fake();

    test()->postJson(vvApiUrl('/webhooks/video/bunny').'?k[]=x', ['VideoGuid' => BZ_GUID])->assertNotFound();
});

// ---- S2(b): so han muc ----

test('S2b: upload, de, prune, upload lai -> han muc ngay KHONG duoc hoan', function () {
    vvCourseActor();
    bzConfigure();
    config(['video.daily_quota_gb' => 2]);
    $gb = 1024 * 1024 * 1024;
    $n = 0;
    bzFakeApi(['POST '.bzVideosPath() => function () use (&$n) {
        $n++;

        return Http::response(bzVideoBody(sprintf('11111111-2222-3333-4444-%012d', $n)), 200);
    }]);
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson, ['size' => 1 * $gb])->assertCreated();
    vvRequestUpload($course, $lesson, ['size' => 1 * $gb])->assertCreated(); // de len; asset dau thanh mo coi

    VideoAsset::query()->update(['created_at' => now()->subHour()]);
    bzFakeApi(['DELETE '.bzVideosPath('/11111111-2222-3333-4444-000000000001') => Http::response([], 200)]);
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    expect(VideoAsset::query()->count())->toBe(1);

    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody('11111111-2222-3333-4444-999999999999'), 200)]);
    vvRequestUpload($course, $lesson, ['size' => 1 * $gb])->assertStatus(422)->assertJsonPath('code', 'VIDEO_QUOTA_EXCEEDED');
    expect((int) DB::table('video_upload_usages')->value('bytes'))->toBe(2 * $gb);
});

test('S2b: phien bo do vi Bunny loi duoc hoan so han muc', function () {
    vvCourseActor();
    bzConfigure();
    config(['video.daily_quota_gb' => 1]);
    bzFakeApi(['POST '.bzVideosPath() => Http::response('err', 500)]);
    [$course, , $lesson] = vvContentSet();
    $size = 1024 * 1024 * 1024;

    vvRequestUpload($course, $lesson, ['size' => $size])->assertStatus(503);
    expect((int) DB::table('video_upload_usages')->value('bytes'))->toBe(0);

    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody(), 200)]);
    vvRequestUpload($course, $lesson, ['size' => $size])->assertCreated();
});

// ---- S4: khong theo redirect ----

test('S4: Bunny tra 302 sang http host la -> VideoProviderException, khong co request thu hai', function () {
    bzConfigure();
    bzFakeApi([
        'GET '.bzVideosPath('/'.BZ_GUID) => Http::response('', 302, ['Location' => 'http://evil.test/steal']),
    ]);

    expect(fn () => bzProvider()->getVideo(BZ_GUID))->toThrow(VideoProviderException::class);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.test'));
});

test('S4: moi request goi Bunny cau hinh khong theo redirect (kiem tren ma nguon)', function () {
    $src = (string) file_get_contents(app_path('Services/Video/Providers/BunnyStreamProvider.php'));

    expect($src)->toContain('->withoutRedirecting()');
});

test('S4: 3xx khi tao/xoa cung la loi, khong coi la xoa xong', function () {
    bzConfigure();
    bzFakeApi([
        'DELETE '.bzVideosPath('/'.BZ_GUID) => Http::response('', 301, ['Location' => 'http://evil.test']),
        'POST '.bzVideosPath() => Http::response('', 307, ['Location' => 'http://evil.test']),
    ]);

    expect(fn () => bzProvider()->deleteVideo(BZ_GUID))->toThrow(VideoProviderException::class)
        ->and(fn () => bzProvider()->createVideo('x'))->toThrow(VideoProviderException::class);
});

// ---- S7: tran TTL phat ----

test('S7: VIDEO_PLAYBACK_TTL_MINUTES duoc kep 1-60 trong config', function (string $env, int $expected) {
    $old = $_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] ?? null;
    $oldServer = $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] ?? null;
    $oldGet = getenv('VIDEO_PLAYBACK_TTL_MINUTES');

    try {
        $_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] = $env;
        putenv('VIDEO_PLAYBACK_TTL_MINUTES='.$env);

        $cfg = require config_path('video.php');

        expect($cfg['playback_ttl_minutes'])->toBe($expected);
    } finally {
        $old === null ? $_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = null : $_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = $old;
        if ($old === null) {
            unset($_ENV['VIDEO_PLAYBACK_TTL_MINUTES']);
        }
        $oldServer === null ? null : $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] = $oldServer;
        if ($oldServer === null) {
            unset($_SERVER['VIDEO_PLAYBACK_TTL_MINUTES']);
        }
        $oldGet === false ? putenv('VIDEO_PLAYBACK_TTL_MINUTES') : putenv('VIDEO_PLAYBACK_TTL_MINUTES='.$oldGet);
    }
})->with([['1440', 60], ['60', 60], ['15', 15], ['0', 1], ['-5', 1]]);

// ---- S8: khong xoa dong khi thu vien khac ----

test('S8: asset thuoc thu vien Bunny khac -> pruner KHONG goi xoa, giu dong, ghi canh bao', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    $lesson->delete();
    VideoAsset::query()->whereKey($asset->id)->update(['created_at' => now()->subHour(), 'provider_library_id' => '555']);
    Http::fake();
    Log::spy();

    test()->artisan('videos:prune-orphans')->assertSuccessful();

    Http::assertNothingSent();
    expect(VideoAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx = []) => str_contains($m, 'thư viện Bunny') && ! str_contains(json_encode($ctx), BZ_API_KEY))->once();
});

test('S8: asset cung thu vien hien hanh van bi xoa binh thuong', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    $lesson->delete();
    VideoAsset::query()->whereKey($asset->id)->update(['created_at' => now()->subHour()]);
    bzFakeApi(['DELETE '.bzVideosPath('/'.BZ_GUID) => Http::response([], 200)]);

    test()->artisan('videos:prune-orphans')->assertSuccessful();

    expect(VideoAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
});

// ---- S9 ----

test('S9: tham so khoa trong BunnySigner co #[SensitiveParameter]', function () {
    $m = new ReflectionClass(BunnySigner::class);

    foreach (['uploadSignature' => 'apiKey', 'directoryToken' => 'tokenKey', 'hlsUrl' => 'tokenKey'] as $method => $param) {
        foreach ($m->getMethod($method)->getParameters() as $p) {
            if ($p->getName() === $param) {
                expect($p->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
            }
        }
    }
});
