<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\Bunny\BunnySigner;
use App\Services\Video\VideoProviderManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

test('name/libraryId lay tu cau hinh bunny, khong tu video.library_id cua VideoLab', function () {
    bzConfigure();
    config(['video.library_id' => 'default']);

    expect(bzProvider()->name())->toBe('bunny')->and(bzProvider()->libraryId())->toBe(BZ_LIB);
});

test('createVideo: POST dung URL, header AccessKey, title cat 255; tra guid + created', function () {
    bzConfigure();
    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody(), 200)]);

    $video = bzProvider()->createVideo(str_repeat('t', 300));

    expect($video->guid)->toBe(BZ_GUID)->and($video->status)->toBe(VideoAssetStatus::Created);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
        && $r->url() === 'https://video.bunnycdn.com'.bzVideosPath()
        && $r->header('AccessKey') === [BZ_API_KEY]
        && mb_strlen($r['title']) === 255);
});

test('createVideo: phan hoi thieu guid / khong phai JSON / guid la -> VideoProviderException', function (mixed $body) {
    bzConfigure();
    bzFakeApi(['POST '.bzVideosPath() => Http::response($body, 200)]);

    expect(fn () => bzProvider()->createVideo('x'))->toThrow(VideoProviderException::class);
})->with([
    'thieu guid' => [['status' => 0]],
    'khong JSON' => ['<html>oops</html>'],
    'guid la' => [['guid' => '../etc/passwd', 'status' => 0]],
    'guid so' => [['guid' => 123, 'status' => 0]],
]);

test('uploadTarget: endpoint, 4 header, chu ky khop BunnySigner, han <= 6 gio, khong lo khoa API', function () {
    bzConfigure();
    $this->travelTo('2030-01-01 00:00:00');

    $target = bzProvider()->uploadTarget(new ProviderVideo(BZ_GUID, VideoAssetStatus::Created), 99 * 3600);
    $expire = now()->addHours(6)->getTimestamp();

    expect($target->protocol)->toBe('tus')->and($target->endpoint)->toBe('https://video.bunnycdn.com/tusupload')
        ->and($target->headers)->toBe([
            'AuthorizationSignature' => BunnySigner::uploadSignature(BZ_LIB, BZ_API_KEY, $expire, BZ_GUID),
            'AuthorizationExpire' => (string) $expire,
            'VideoId' => BZ_GUID,
            'LibraryId' => BZ_LIB,
        ])->and($target->expiresAt->getTimestamp())->toBe($expire);
    bzAssertNoSecret(json_encode($target->headers).$target->endpoint);
});

test('uploadTarget: ttl nho duoc giu (>= 60s); guid sai -> loi', function () {
    bzConfigure();

    expect(bzProvider()->uploadTarget(new ProviderVideo(BZ_GUID, VideoAssetStatus::Created), 600)->expiresAt->getTimestamp())
        ->toBeBetween(now()->addSeconds(599)->getTimestamp(), now()->addSeconds(601)->getTimestamp());
    expect(fn () => bzProvider()->uploadTarget(new ProviderVideo('x y', VideoAssetStatus::Created), 600))->toThrow(VideoProviderException::class);
});

test('getVideo anh xa trang thai: 0..6 va ma la (7, 8, 99, chuoi) giu processing, khong failed', function (mixed $code, VideoAssetStatus $expected) {
    bzConfigure();
    $body = ['guid' => BZ_GUID, 'status' => $code, 'length' => 61.6];
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response($body, 200)]);

    $video = bzProvider()->getVideo(BZ_GUID);

    expect($video->status)->toBe($expected)
        ->and($video->durationSeconds)->toBe($expected === VideoAssetStatus::Ready ? 62 : null)
        ->and($video->errorMessage !== null)->toBe($expected === VideoAssetStatus::Failed);
})->with([
    '0 created' => [0, VideoAssetStatus::Created],
    '1 uploaded' => [1, VideoAssetStatus::Processing],
    '2 processing' => [2, VideoAssetStatus::Processing],
    '3 transcoding' => [3, VideoAssetStatus::Processing],
    '4 finished' => [4, VideoAssetStatus::Ready],
    '5 error' => [5, VideoAssetStatus::Failed],
    '6 upload failed' => [6, VideoAssetStatus::Failed],
    '7 la' => [7, VideoAssetStatus::Processing],
    '8 la' => [8, VideoAssetStatus::Processing],
    '99 la' => [99, VideoAssetStatus::Processing],
    '-1 la' => [-1, VideoAssetStatus::Processing],
    'chuoi la' => ['abc', VideoAssetStatus::Processing],
    'null' => [null, VideoAssetStatus::Created],
]);

test('getVideo: finished ma thieu/sai length -> VideoProviderException (khong luu nua voi)', function (array $extra) {
    bzConfigure();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(array_merge(['guid' => BZ_GUID, 'status' => 4], $extra), 200)]);

    expect(fn () => bzProvider()->getVideo(BZ_GUID))->toThrow(VideoProviderException::class);
})->with([[[]], [['length' => null]], [['length' => 'abc']], [['length' => []]]]);

test('getVideo: thong diep loi cua failed la tieng Viet co dinh, khong chep noi dung Bunny', function () {
    bzConfigure();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(['guid' => BZ_GUID, 'status' => 5, 'encodeProgress' => 3, 'error' => 'https://internal.example/secret?AccessKey='.BZ_API_KEY], 200)]);

    $msg = (string) bzProvider()->getVideo(BZ_GUID)->errorMessage;

    expect($msg)->toContain('Vui lòng')->and($msg)->not->toContain('http');
    bzAssertNoSecret($msg);
});

test('getVideo/deleteVideo: guid khong hop le -> loi, KHONG goi mang', function () {
    bzConfigure();
    Http::fake();

    expect(fn () => bzProvider()->getVideo('../../library'))->toThrow(VideoProviderException::class)
        ->and(fn () => bzProvider()->deleteVideo('abc'))->toThrow(VideoProviderException::class)
        ->and(fn () => bzProvider()->getVideo('pending-'.BZ_GUID))->toThrow(VideoProviderException::class);
    Http::assertNothingSent();
});

test('getVideo 404 -> VideoNotFoundException; 5xx/429/403 -> VideoProviderException', function (int $status, string $class) {
    bzConfigure();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(['message' => 'x'], $status)]);

    expect(fn () => bzProvider()->getVideo(BZ_GUID))->toThrow($class);
})->with([
    '404' => [404, VideoNotFoundException::class],
    '500' => [500, VideoProviderException::class],
    '503' => [503, VideoProviderException::class],
    '429' => [429, VideoProviderException::class],
    '403' => [403, VideoProviderException::class],
]);

test('loi mang/timeout -> VideoProviderException, message khong chua khoa hay URL', function () {
    bzConfigure();
    bzFakeApi([
        'GET '.bzVideosPath('/'.BZ_GUID) => fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://video.bunnycdn.com/library/777 with AccessKey '.BZ_API_KEY),
        'POST '.bzVideosPath() => fn () => throw new ConnectionException('boom '.BZ_API_KEY),
        'DELETE '.bzVideosPath('/'.BZ_GUID) => fn () => throw new ConnectionException('boom '.BZ_API_KEY),
    ]);

    foreach ([fn () => bzProvider()->getVideo(BZ_GUID), fn () => bzProvider()->createVideo('x'), fn () => bzProvider()->deleteVideo(BZ_GUID)] as $call) {
        try {
            $call();
            $this->fail('phải ném');
        } catch (VideoProviderException $e) {
            bzAssertNoSecret($e->getMessage());
            expect($e->getMessage())->not->toContain('http')->and($e->getPrevious())->toBeNull();
        }
    }
});

test('401 -> VideoProviderException + log "Bunny tu choi khoa" khong chua khoa', function () {
    bzConfigure();
    Log::spy();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(['message' => 'Unauthorized'], 401)]);

    try {
        bzProvider()->getVideo(BZ_GUID);
        $this->fail('phải ném');
    } catch (VideoProviderException $e) {
        bzAssertNoSecret($e->getMessage());
    }

    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx = []) => str_contains($m, 'Bunny từ chối khoá')
        && ! str_contains($m.json_encode($ctx), BZ_API_KEY))->once();
});

test('deleteVideo: 200 va 404 coi la xong; 5xx/401 -> loi', function () {
    bzConfigure();
    $path = 'DELETE '.bzVideosPath('/'.BZ_GUID);

    bzFakeApi([$path => Http::response(['success' => true], 200)]);
    bzProvider()->deleteVideo(BZ_GUID);
    bzFakeApi([$path => Http::response(['message' => 'not found'], 404)]);
    bzProvider()->deleteVideo(BZ_GUID);

    foreach ([500, 401, 429] as $status) {
        bzFakeApi([$path => Http::response([], $status)]);
        expect(fn () => bzProvider()->deleteVideo(BZ_GUID))->toThrow(VideoProviderException::class);
    }

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->header('AccessKey') === [BZ_API_KEY]);
});

test('playback: HLS qua CDN, token ky theo /{guid}/, han = ttl, rang IP khi co IP', function () {
    bzConfigure();
    $this->travelTo('2030-01-01 00:00:00');
    $asset = VideoAsset::factory()->make(['provider' => 'bunny', 'provider_video_id' => BZ_GUID, 'status' => VideoAssetStatus::Ready]);
    $exp = now()->addSeconds(900)->getTimestamp();

    $anon = bzProvider()->playback($asset, new PlaybackContext(1, null, 900));
    $bound = bzProvider()->playback($asset, new PlaybackContext(1, '203.0.113.7', 900));
    $other = bzProvider()->playback($asset, new PlaybackContext(1, '203.0.113.8', 900));

    expect($anon->kind)->toBe('hls')->and($anon->expiresAt->getTimestamp())->toBe($exp)
        ->and($anon->url)->toBe(BunnySigner::hlsUrl('https://'.BZ_CDN, BZ_GUID, BZ_TOKEN_KEY, $exp))
        ->and($bound->url)->toBe(BunnySigner::hlsUrl('https://'.BZ_CDN, BZ_GUID, BZ_TOKEN_KEY, $exp, '203.0.113.7'))
        ->and($bound->url)->not->toBe($anon->url)->and($other->url)->not->toBe($bound->url)
        ->and($anon->url)->toStartWith('https://'.BZ_CDN.'/bcdn_token=')->and($anon->url)->toEndWith('/'.BZ_GUID.'/playlist.m3u8');
    // Khoá ký không nằm trong URL; IP không lộ trong URL.
    expect($bound->url)->not->toContain(BZ_TOKEN_KEY)->and($bound->url)->not->toContain('203.0.113.7')->and($bound->url)->not->toContain(BZ_API_KEY);
});

test('playback: cdn_host dang https://host/ van dung; http:// hoac co duong dan -> loi', function () {
    $asset = VideoAsset::factory()->make(['provider' => 'bunny', 'provider_video_id' => BZ_GUID]);
    $ctx = new PlaybackContext(1, null, 900);

    bzConfigure(['cdn_host' => 'https://video.vitaminvui.vn/']);
    expect(bzProvider()->playback($asset, $ctx)->url)->toStartWith('https://video.vitaminvui.vn/bcdn_token=');

    foreach (['http://vz.b-cdn.net', 'ftp://vz.b-cdn.net', 'vz.b-cdn.net/path', 'vz b-cdn.net', 'https://vz.b-cdn.net@evil.com'] as $bad) {
        bzConfigure(['cdn_host' => $bad]);
        expect(fn () => bzProvider()->playback($asset, $ctx))->toThrow(VideoProviderException::class);
    }
});

test('parseWebhook: chi lay VideoGuid hop le (chuan hoa chu thuong), con lai null', function (mixed $body, ?string $expected) {
    $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], is_string($body) ? $body : json_encode($body));

    expect(bzProvider()->parseWebhook($request))->toBe($expected);
})->with([
    'hop le' => [['VideoGuid' => BZ_GUID, 'Status' => 4, 'VideoLibraryId' => 777], BZ_GUID],
    'chu hoa' => [['VideoGuid' => strtoupper(BZ_GUID)], BZ_GUID],
    'thieu' => [['Status' => 4], null],
    'mang' => [['VideoGuid' => [BZ_GUID]], null],
    'so' => [['VideoGuid' => 12345], null],
    'khong phai uuid' => [['VideoGuid' => 'abc'], null],
    'path traversal' => [['VideoGuid' => '../'.BZ_GUID], null],
    'chen ky tu' => [['VideoGuid' => BZ_GUID."\n"], null],
    'khong JSON' => ['not json', null],
    'rong' => ['', null],
]);

test('thieu cau hinh: manager nem VideoProviderException neu dung ten bien, khong lo gia tri khac', function (string $key, string $env) {
    bzConfigure([$key => null]);

    try {
        app(VideoProviderManager::class)->driver('bunny');
        $this->fail('phải ném');
    } catch (VideoProviderException $e) {
        expect($e->getMessage())->toContain($env);
        bzAssertNoSecret($e->getMessage());
    }
})->with([
    ['library_id', 'BUNNY_LIBRARY_ID'], ['api_key', 'BUNNY_API_KEY'], ['cdn_host', 'BUNNY_CDN_HOST'], ['token_key', 'BUNNY_TOKEN_KEY'],
]);

test('thieu cau hinh: chuoi rong/khoang trang cung tinh la thieu; du cau hinh -> manager tra adapter bunny', function () {
    bzConfigure(['token_key' => '   ']);
    expect(fn () => app(VideoProviderManager::class)->driver('bunny'))->toThrow(VideoProviderException::class);

    bzConfigure();
    expect(app(VideoProviderManager::class)->driver('bunny')->name())->toBe('bunny');
});

test('moi lenh HTTP co timeout toi da 10s va khong retry (kiem tren ma nguon)', function () {
    $src = (string) file_get_contents(app_path('Services/Video/Providers/BunnyStreamProvider.php'));

    expect($src)->toContain('->timeout(10)')->and($src)->toContain('->connectTimeout(5)')->and($src)->not->toContain('->retry(');
});
