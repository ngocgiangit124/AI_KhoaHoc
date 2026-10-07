<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T13/helpers.php';

// QA T37: bổ sung theo gợi ý security (review-T37.md, mục "Kiểm lại").

test('QA R-2: webhook Finished thu hai trong 10s -> job tre dong bo dung, asset ready + duration (queue sync)', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 3), 200)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent(); // webhook 1: dang ma hoa
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Processing);

    // webhook 2 den sat (trong 10 s) va bi gom; queue `sync` chay job ngay (tren staging la tre 15 s).
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 125.6), 200)]);
    bzWebhook(['VideoGuid' => BZ_GUID, 'Status' => 3])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($asset->fresh()->duration_seconds)->toBe(126)
        ->and($lesson->fresh()->duration_seconds)->toBe(126);
    Http::assertSentCount(1);
});

test('QA R-2: webhook thu ba sau khi ready -> khong goi Bunny, khong xep job', function () {
    bzConfigure();
    bzLessonWithAsset(VideoAssetStatus::Processing);
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 10), 200)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    expect(VideoAsset::query()->sole()->status)->toBe(VideoAssetStatus::Ready);

    Http::swap(new Factory);
    Http::fake();
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();
    Http::assertNothingSent();
});

test('QA S1: apply() nem loi KHAC VideoProviderException -> khoa gom tu het sau 10s, webhook ke tiep van xu ly', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 30), 200)]);

    $fail = true;
    DB::listen(function ($q) use (&$fail) {
        if ($fail && str_starts_with(strtolower($q->sql), 'update `video_assets`')) {
            throw new RuntimeException('DB chet gia lap');
        }
    });

    bzWebhook(['VideoGuid' => BZ_GUID])->assertStatus(500);
    $key = 'video-webhook:'.$asset->getKey();
    $fail = false;
    expect(Cache::has($key))->toBeTrue()->and($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);

    $this->travel(11)->seconds(); // khoa tu het han (khong bi ket)
    expect(Cache::has($key))->toBeFalse();

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($asset->fresh()->duration_seconds)->toBe(30);
});

test('QA S1: loi khong phai VideoProviderException tu getVideo -> khoa het han sau 10s', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => fn () => throw new RuntimeException('loi la')]);

    bzWebhook(['VideoGuid' => BZ_GUID])->assertStatus(500);
    expect(Cache::has('video-webhook:'.$asset->getKey()))->toBeTrue();

    $this->travel(11)->seconds();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 7), 200)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready);
});

test('QA S4: Bunny tra 302 khi dong bo qua webhook -> 503 (de gui lai), dung 1 request, khong den host la', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Cache::flush();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response('', 302, ['Location' => 'http://evil.test/x'])]);

    bzWebhook(['VideoGuid' => BZ_GUID])->assertStatus(503);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.test'));
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
});

test('QA S4: Bunny tra 302 khi tao phien upload -> 503, khong request thu hai, han muc duoc hoan', function () {
    vvCourseActor();
    bzConfigure();
    bzFakeApi(['POST '.bzVideosPath() => Http::response('', 302, ['Location' => 'http://evil.test/x'])]);
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');

    Http::assertSentCount(1);
    expect((int) DB::table('video_upload_usages')->value('bytes'))->toBe(0);
});

test('QA S7: TTL phat qua API bi kep: env 1440 -> expires_at <= 60 phut, token cung han', function () {
    $s = vvLearnSet();
    bzConfigure();
    $s['asset']->forceFill(['provider' => 'bunny', 'provider_video_id' => BZ_GUID])->save();

    $prev = [$_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] ?? null, $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] ?? null, getenv('VIDEO_PLAYBACK_TTL_MINUTES')];
    try {
        $_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] = '1440';
        putenv('VIDEO_PLAYBACK_TTL_MINUTES=1440');
        config(['video.playback_ttl_minutes' => (require config_path('video.php'))['playback_ttl_minutes']]);
    } finally {
        $prev[0] === null ? ($_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = null) : ($_ENV['VIDEO_PLAYBACK_TTL_MINUTES'] = $prev[0]);
        if ($prev[0] === null) {
            unset($_ENV['VIDEO_PLAYBACK_TTL_MINUTES']);
        }
        if ($prev[1] === null) {
            unset($_SERVER['VIDEO_PLAYBACK_TTL_MINUTES']);
        } else {
            $_SERVER['VIDEO_PLAYBACK_TTL_MINUTES'] = $prev[1];
        }
        $prev[2] === false ? putenv('VIDEO_PLAYBACK_TTL_MINUTES') : putenv('VIDEO_PLAYBACK_TTL_MINUTES='.$prev[2]);
    }

    $res = vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback", ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
    $exp = strtotime($res->json('expires_at'));
    expect($exp)->toBeLessThanOrEqual(time() + 60 * 60 + 5)->and($exp)->toBeGreaterThan(time());
    expect($res->json('url'))->toContain('expires='.$exp);
});

test('QA S8: pruner khong xoa khi provider_library_id khac, ca khi Bunny dang that bai/404 (khong goi mang)', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    $lesson->delete();
    VideoAsset::query()->whereKey($asset->id)->update(['created_at' => now()->subHour(), 'provider_library_id' => '1']);
    bzFakeApi(['DELETE '.bzVideosPath('/'.BZ_GUID) => Http::response([], 404)]); // neu bi goi se thanh "da xoa"

    test()->artisan('videos:prune-orphans')->assertSuccessful();
    test()->artisan('videos:prune-orphans')->assertSuccessful(); // lap lai van giu

    Http::assertNothingSent();
    expect(VideoAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
});

test('QA BR3: khoa/bi mat khong xuat hien trong log, exception, response o moi luong loi', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
    $texts = [];
    vvCourseActor();
    bzConfigure();
    [$course, , $lesson] = vvContentSet();
    [, , $asset] = bzLessonWithAsset();
    Cache::flush();
    $guidPath = bzVideosPath('/'.BZ_GUID);

    foreach ([401, 500, 302] as $code) {
        bzFakeApi(['POST '.bzVideosPath() => Http::response('AccessKey '.BZ_API_KEY, $code), 'GET '.$guidPath => Http::response('AccessKey '.BZ_API_KEY, $code)]);
        $texts[] = vvRequestUpload($course, $lesson)->getContent();
        Cache::flush();
        $texts[] = bzWebhook(['VideoGuid' => BZ_GUID])->getContent();
        $texts[] = bzWebhook(['VideoGuid' => BZ_GUID], BZ_WEBHOOK_TOKEN.'sai')->getContent();
    }
    bzFakeApi(['POST '.bzVideosPath() => fn () => throw new ConnectionException('boom '.BZ_API_KEY.' '.BZ_TOKEN_KEY)]);
    $texts[] = vvRequestUpload($course, $lesson)->getContent();

    foreach ([fn () => bzProvider()->getVideo(BZ_GUID), fn () => bzProvider()->deleteVideo(BZ_GUID)] as $call) {
        bzFakeApi(['GET '.$guidPath => Http::response('x', 401), 'DELETE '.$guidPath => Http::response('x', 401)]);
        try {
            $call();
        } catch (VideoProviderException $e) {
            $texts[] = $e->getMessage().$e->getTraceAsString();
        }
    }

    // pruner 5xx + playback + upload thanh cong
    bzLessonWithAsset(VideoAssetStatus::Uploading, '99999999-2222-3333-4444-555555555555')[1]->delete();
    VideoAsset::query()->where('provider_video_id', '99999999-2222-3333-4444-555555555555')->update(['created_at' => now()->subHour()]);
    bzFakeApi(['DELETE '.bzVideosPath('/99999999-2222-3333-4444-555555555555') => Http::response('AccessKey '.BZ_API_KEY, 500)]);
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody('88888888-2222-3333-4444-555555555555'), 200)]);
    $texts[] = vvRequestUpload($course, $lesson)->getContent();

    expect($logged)->not->toBeEmpty();
    foreach (array_merge($texts, $logged) as $t) {
        bzAssertNoSecret((string) $t);
    }
    // upload thanh cong chi tra chu ky da bam, khong tra khoa
    expect(end($texts))->toContain('AuthorizationSignature')->and($asset->id)->toBeInt();
    unset($asset);
});
