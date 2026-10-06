<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\InternalVideoProvider;
use App\Services\Video\VideoProviderManager;
use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\MediaToolkit;
use App\VideoLab\Services\TranscodeService;
use App\VideoLab\Support\Signature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T09/helpers.php';

/** Http::fake cộng dồn stub (stub đầu thắng): đặt lại factory trước mỗi lần fake mới. */
function vlHttpFake(mixed $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

const VL_GUID = '11111111-2222-3333-4444-555555555555';

beforeEach(function () {
    $this->vlDir = vlUseTempStorage();
    config(['video.provider' => 'internal', 'video.enabled_providers' => ['internal'], 'videolab.enabled' => true]);
});

afterEach(fn () => vlCleanup($this->vlDir));

test('manager: driver internal tra InternalVideoProvider (allowlist), tat VideoLab -> loi', function () {
    $manager = app(VideoProviderManager::class);

    expect($manager->driver('internal'))->toBeInstanceOf(InternalVideoProvider::class)
        ->and(app(VideoProvider::class)->name())->toBe('internal');

    config(['videolab.enabled' => false]);
    app()->forgetInstance(VideoProviderManager::class);
    expect(fn () => app(VideoProviderManager::class)->driver('internal'))->toThrow(VideoProviderException::class);
});

test('provider: createVideo goi API noi bo kem AccessKey + Host video, timeout <= 10s', function () {
    vlHttpFake(['*' => Http::response(['guid' => VL_GUID, 'status' => 0, 'length' => 0], 201)]);

    $video = app(InternalVideoProvider::class)->createVideo('lesson-7');

    expect($video->guid)->toBe(VL_GUID)->and($video->status)->toBe(VideoAssetStatus::Created);
    Http::assertSent(function (HttpRequest $r) {
        return $r->method() === 'POST'
            && str_ends_with($r->url(), '/videolab/library/'.vlLibrary().'/videos')
            && $r->header('AccessKey') === [config('videolab.api_key')]
            && $r->header('Host') === [config('videolab.host')]
            && $r['title'] === 'lesson-7';
    });
});

test('provider: HTTP client dat timeout/connectTimeout <= 10s', function () {
    $captured = null;
    vlHttpFake(function (HttpRequest $r, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(['guid' => VL_GUID, 'status' => 0], 201);
    });

    app(InternalVideoProvider::class)->createVideo('x');

    expect($captured['timeout'])->toBeLessThanOrEqual(10)->and($captured['connect_timeout'])->toBeLessThanOrEqual(10);
});

test('provider: 5xx / mat ket noi / 401 -> VideoProviderException; khong lo khoa trong message', function () {
    $provider = app(InternalVideoProvider::class);

    vlHttpFake(['*' => Http::response('boom', 500)]);
    expect(fn () => $provider->createVideo('x'))->toThrow(VideoProviderException::class);

    vlHttpFake(fn () => throw new ConnectionException('cURL error ...'));
    expect(fn () => $provider->getVideo(VL_GUID))->toThrow(VideoProviderException::class, 'Không kết nối được VideoLab.');

    vlHttpFake(['*' => Http::response('', 401)]);
    try {
        $provider->getVideo(VL_GUID);
    } catch (VideoProviderException $e) {
        expect($e->getMessage())->not->toContain((string) config('videolab.api_key'));
    }

    vlHttpFake(['*' => Http::response(['nope' => 1], 201)]);
    expect(fn () => $provider->createVideo('x'))->toThrow(VideoProviderException::class);
});

test('provider: getVideo anh xa trang thai Bunny 0-6 va 404', function () {
    $provider = app(InternalVideoProvider::class);
    $map = [
        [0, false, VideoAssetStatus::Created], [0, true, VideoAssetStatus::Uploading], [1, true, VideoAssetStatus::Processing],
        [2, true, VideoAssetStatus::Processing], [3, true, VideoAssetStatus::Processing], [4, true, VideoAssetStatus::Ready],
        [5, true, VideoAssetStatus::Failed], [6, true, VideoAssetStatus::Failed],
    ];

    foreach ($map as [$code, $started, $expected]) {
        vlHttpFake(['*' => Http::response(['guid' => VL_GUID, 'status' => $code, 'length' => 99, 'uploadStarted' => $started, 'error' => $code >= 5 ? 'Lỗi X' : null])]);
        $v = $provider->getVideo(VL_GUID);
        expect($v->status)->toBe($expected)
            ->and($v->durationSeconds)->toBe($expected === VideoAssetStatus::Ready ? 99 : null)
            ->and($v->errorMessage)->toBe($expected === VideoAssetStatus::Failed ? 'Lỗi X' : null);
    }

    vlHttpFake(['*' => Http::response('', 404)]);
    expect(fn () => $provider->getVideo(VL_GUID))->toThrow(VideoNotFoundException::class);
    expect(fn () => $provider->getVideo('../../etc'))->toThrow(VideoProviderException::class);
});

test('provider: deleteVideo 404 = da xoa; 5xx -> loi', function () {
    $provider = app(InternalVideoProvider::class);

    vlHttpFake(['*' => Http::response('', 204)]);
    $provider->deleteVideo(VL_GUID);
    vlHttpFake(['*' => Http::response('', 404)]);
    $provider->deleteVideo(VL_GUID);
    vlHttpFake(['*' => Http::response('', 503)]);
    expect(fn () => $provider->deleteVideo(VL_GUID))->toThrow(VideoProviderException::class);
});

test('provider: uploadTarget cap header Bunny-style, TTL cat <= 6h, chu ky duoc TUS chap nhan', function () {
    $provider = app(InternalVideoProvider::class);
    $guid = vlCreateVideo();

    $target = $provider->uploadTarget(new ProviderVideo($guid, VideoAssetStatus::Created), 99 * 3600);

    expect($target->protocol)->toBe('tus')->and($target->endpoint)->toEndWith('/videolab/tus')
        ->and(array_keys($target->headers))->toBe(['AuthorizationSignature', 'AuthorizationExpire', 'VideoId', 'LibraryId'])
        ->and($target->expiresAt->getTimestamp())->toBeLessThanOrEqual(now()->addHours(6)->getTimestamp() + 2);

    // chữ ký dùng được thật với TUS (adapter và module khớp công thức)
    $r = test()->call('POST', vlUrl('/tus'), [], [], [], vlServer(array_merge($target->headers, ['Tus-Resumable' => '1.0.0', 'Upload-Length' => '100'])));
    $r->assertCreated();
    expect($target->headers['AuthorizationSignature'])->toBe(Signature::upload(vlLibrary(), (int) $target->headers['AuthorizationExpire'], $guid));
});

test('provider: playback URL HLS co token, het han, rang IP - CDN chap nhan', function () {
    $guid = vlFinishedVideo();
    $asset = VideoAsset::factory()->make(['provider_video_id' => $guid]);
    $provider = app(InternalVideoProvider::class);

    $info = $provider->playback($asset, new PlaybackContext(5, '10.1.1.1', 900));
    expect($info->kind)->toBe('hls')->and($info->url)->toContain('/videolab/cdn/')->and($info->url)->toEndWith("/{$guid}/playlist.m3u8")
        ->and($info->expiresAt->getTimestamp())->toBeBetween(time() + 890, time() + 910);

    $local = preg_replace('#^https?://[^/]+#', 'http://'.config('videolab.host'), $info->url);
    test()->call('GET', $local, [], [], [], ['REMOTE_ADDR' => '10.1.1.1'])->assertOk();
    test()->call('GET', $local, [], [], [], ['REMOTE_ADDR' => '10.1.1.2'])->assertForbidden();

    $noIp = $provider->playback($asset, new PlaybackContext(5, null, 900));
    $localNoIp = preg_replace('#^https?://[^/]+#', 'http://'.config('videolab.host'), $noIp->url);
    test()->call('GET', $localNoIp, [], [], [], ['REMOTE_ADDR' => '10.1.1.2'])->assertOk();
});

test('provider: parseWebhook chi tin payload co chu ky hop le', function () {
    $provider = app(InternalVideoProvider::class);
    $body = json_encode(['VideoGuid' => VL_GUID, 'Status' => 4]);
    $make = fn (string $b, ?string $sig) => Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_VIDEOLAB_SIGNATURE' => (string) $sig], $b);

    expect($provider->parseWebhook($make($body, Signature::webhook($body))))->toBe(VL_GUID)
        ->and($provider->parseWebhook($make($body, null)))->toBeNull()
        ->and($provider->parseWebhook($make($body, 'sai')))->toBeNull()
        ->and($provider->parseWebhook($make($body.' ', Signature::webhook($body))))->toBeNull();

    $bad = json_encode(['VideoGuid' => '../../x']);
    expect($provider->parseWebhook($make($bad, Signature::webhook($bad))))->toBeNull();
});

test('webhook job: ky HMAC, Host api, timeout <= 10s; loi HTTP -> nem de retry; backoff 10/60/300', function () {
    $guid = vlFinishedVideo();
    $captured = null;
    vlHttpFake(function (HttpRequest $r, array $options) use (&$captured) {
        $captured = [$r, $options];

        return Http::response('', 204);
    });

    $job = new SendVideoLabWebhookJob($guid);
    $job->handle();

    [$request, $options] = $captured;
    expect($request->header('X-VideoLab-Signature'))->toBe([Signature::webhook($request->body())])
        ->and($request->header('Host'))->toBe([config('app.api_host')])
        ->and($request->url())->toBe(config('videolab.webhook_url'))
        ->and(json_decode($request->body(), true))->toBe(['VideoLibraryId' => vlLibrary(), 'VideoGuid' => $guid, 'Status' => 4])
        ->and($options['timeout'])->toBeLessThanOrEqual(10);
    expect($job->backoff())->toBe([10, 60, 300])->and($job->tries)->toBe(4);

    vlHttpFake(['*' => Http::response('', 503)]);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
});

test('E2E trong tien trinh: tao -> TUS upload -> transcode -> webhook that -> video_assets ready + bai co thoi luong', function () {
    Queue::fake();
    $toolkit = new FakeMediaToolkit;
    app()->instance(MediaToolkit::class, $toolkit);

    [, , $lesson] = vvContentSet();

    // Adapter gọi VideoLab qua HTTP: chuyển tiếp vào route trong tiến trình.
    vlHttpFake(function (HttpRequest $r) {
        $path = parse_url($r->url(), PHP_URL_PATH);

        if (str_starts_with((string) $path, '/videolab/')) {
            $resp = test()->call($r->method(), vlUrl(substr((string) $path, strlen('/videolab'))), [], [], [], vlServer([
                'AccessKey' => (string) $r->header('AccessKey')[0], 'Content-Type' => 'application/json', 'Accept' => 'application/json',
            ]), $r->body());

            return Http::response($resp->getContent() === '' ? '' : $resp->json(), $resp->getStatusCode());
        }

        return Http::response('', 204);
    });

    $provider = app(VideoProvider::class);
    $created = $provider->createVideo('lesson-'.$lesson->id);
    $target = $provider->uploadTarget($created, 3600);
    $asset = VideoAsset::factory()->create([
        'provider' => 'internal', 'provider_video_id' => $created->guid, 'status' => VideoAssetStatus::Uploading, 'lesson_id' => $lesson->id,
    ]);
    $lesson->forceFill(['video_source' => 'upload', 'video_asset_id' => $asset->id])->save();

    // TUS đúng như tus-js-client sẽ làm, headers lấy từ adapter
    $content = vlFakeMp4(5000);
    test()->call('POST', vlUrl('/tus'), [], [], [], vlServer($target->headers + ['Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) strlen($content), 'Upload-Metadata' => 'filename '.base64_encode('bai1.mp4')]))->assertCreated();
    test()->call('PATCH', vlUrl('/tus/'.$created->guid), [], [], [], vlServer($target->headers + ['Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream']), $content)->assertNoContent();

    // Trạng thái từ góc nhìn nghiệp vụ trước khi transcode: đang xử lý
    expect($provider->getVideo($created->guid)->status)->toBe(VideoAssetStatus::Processing);

    // worker video
    Queue::assertPushed(TranscodeVideoJob::class);
    (new TranscodeVideoJob($created->guid))->handle(app(TranscodeService::class));

    // webhook (đúng body+chữ ký mà SendVideoLabWebhookJob gửi) → API nghiệp vụ → getVideo() xác thực
    $webhookRequest = null;
    vlHttpFake(function (HttpRequest $r) use (&$webhookRequest) {
        $webhookRequest = $r;

        return Http::response('', 204);
    });
    (new SendVideoLabWebhookJob($created->guid))->handle();
    expect($webhookRequest)->not->toBeNull();

    vlHttpFake(function (HttpRequest $r) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $resp = test()->call('GET', vlUrl(substr($path, strlen('/videolab'))), [], [], [], vlServer(['AccessKey' => (string) $r->header('AccessKey')[0]]));

        return Http::response($resp->json(), $resp->getStatusCode());
    });

    test()->call('POST', vvApiUrl('/webhooks/video/internal'), [], [], [], vlServer([
        'Content-Type' => 'application/json', 'Accept' => 'application/json',
        'X-VideoLab-Signature' => $webhookRequest->header('X-VideoLab-Signature')[0],
    ]), $webhookRequest->body())->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($asset->fresh()->duration_seconds)->toBe(125)
        ->and($lesson->fresh()->duration_seconds)->toBe(125);

    // webhook với chữ ký sai: vẫn 204 nhưng bị bỏ qua (không đồng bộ)
    $other = vlFinishedVideo();
    $asset2 = VideoAsset::factory()->create(['provider' => 'internal', 'provider_video_id' => $other, 'status' => VideoAssetStatus::Uploading]);
    $body = json_encode(['VideoGuid' => $other]);
    test()->call('POST', vvApiUrl('/webhooks/video/internal'), [], [], [], vlServer(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-VideoLab-Signature' => 'sai']), $body)->assertNoContent();
    expect($asset2->fresh()->status)->toBe(VideoAssetStatus::Uploading);

    // phát: URL do adapter cấp phát được thật
    $info = $provider->playback($asset->fresh(), new PlaybackContext(1, null, 900));
    expect($info->url)->toContain($created->guid);

    // xoá qua adapter dọn sạch VideoLab
    vlHttpFake(function (HttpRequest $r) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $resp = test()->call('DELETE', vlUrl(substr($path, strlen('/videolab'))), [], [], [], vlServer(['AccessKey' => (string) $r->header('AccessKey')[0]]));

        return Http::response('', $resp->getStatusCode());
    });
    $provider->deleteVideo($created->guid);
    expect(Video::query()->where('guid', $created->guid)->exists())->toBeFalse();
});
