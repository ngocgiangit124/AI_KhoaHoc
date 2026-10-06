<?php

use App\Models\VideoAsset;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Providers\InternalVideoProvider;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\MediaToolkit;
use App\VideoLab\Support\Signature;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T03/helpers.php';

/** Fake MediaToolkit: không cần ffmpeg, ghi file HLS giả. */
class FakeMediaToolkit extends MediaToolkit
{
    /** @var array{duration: float, width: int, height: int, has_audio: bool} */
    public array $info = ['duration' => 125.4, 'width' => 1920, 'height' => 1080, 'has_audio' => true];

    public ?Throwable $probeError = null;

    public bool $failEncode = false;

    public int $probeCalls = 0;

    public int $encodeCalls = 0;

    public function probe(string $source, string $format): array
    {
        $this->probeCalls++;

        if ($this->probeError !== null) {
            throw $this->probeError;
        }

        return $this->info;
    }

    public function encodeRendition(string $source, string $format, string $outDir, int $height, int $videoKbps, int $audioKbps, bool $hasAudio): void
    {
        $this->encodeCalls++;

        if ($this->failEncode) {
            throw new RuntimeException('ffmpeg thất bại (giả lập)');
        }

        file_put_contents($outDir.'/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
        file_put_contents($outDir.'/seg_00000.ts', 'TS'.$height);
    }
}

/** Thư mục lưu trữ riêng cho test (xoá khi xong). */
function vlUseTempStorage(): string
{
    $dir = sys_get_temp_dir().'/vl-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    config(['filesystems.disks.videolab.root' => $dir]);

    return $dir;
}

function vlCleanup(string $dir): void
{
    File::deleteDirectory($dir);
}

function vlUrl(string $path): string
{
    return 'http://'.config('videolab.host').'/videolab'.$path;
}

function vlLibrary(): string
{
    return (string) config('video.library_id');
}

function vlAccessHeaders(): array
{
    return ['AccessKey' => (string) config('videolab.api_key')];
}

/** Tạo video qua API quản lý, trả guid. */
function vlCreateVideo(array $over = []): string
{
    $r = test()->postJson(vlUrl('/library/'.vlLibrary().'/videos'), array_merge(['title' => 'lesson-1'], $over), vlAccessHeaders());
    $r->assertCreated();

    return (string) $r->json('guid');
}

/** @return array<string, string> */
function vlTusHeaders(string $guid, ?int $expire = null, ?string $library = null, array $extra = []): array
{
    $expire ??= time() + 3600;
    $library ??= vlLibrary();

    return array_merge([
        'Tus-Resumable' => '1.0.0',
        'AuthorizationSignature' => Signature::upload($library, $expire, $guid),
        'AuthorizationExpire' => (string) $expire,
        'VideoId' => $guid,
        'LibraryId' => $library,
    ], $extra);
}

function vlTusCreate(string $guid, int $length, array $headers = []): TestResponse
{
    return test()->call('POST', vlUrl('/tus'), [], [], [], vlServer(array_merge(vlTusHeaders($guid, null, null, ['Upload-Length' => (string) $length]), $headers)));
}

function vlTusPatch(string $guid, int $offset, string $body, array $headers = []): TestResponse
{
    return test()->call('PATCH', vlUrl('/tus/'.$guid), [], [], [], vlServer(array_merge(
        vlTusHeaders($guid, null, null, ['Upload-Offset' => (string) $offset, 'Content-Type' => 'application/offset+octet-stream']),
        $headers
    )), $body);
}

function vlTusHead(string $guid, array $headers = []): TestResponse
{
    return test()->call('HEAD', vlUrl('/tus/'.$guid), [], [], [], vlServer(array_merge(vlTusHeaders($guid), $headers)));
}

/** @param  array<string, string>  $headers */
function vlServer(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
    }

    return $server;
}

/** Nội dung giả MP4 hợp lệ về magic bytes (ftyp ở byte 4–7). */
function vlFakeMp4(int $size = 64): string
{
    return str_pad("\x00\x00\x00\x18ftypisom", $size, "\x00");
}

/** Upload trọn vẹn 1 file qua TUS (1 chunk), trả guid. */
function vlUploadWhole(string $content, ?string $guid = null): string
{
    $guid ??= vlCreateVideo();
    vlTusCreate($guid, strlen($content))->assertCreated();
    vlTusPatch($guid, 0, $content);

    return $guid;
}

function vlVideo(string $guid): Video
{
    return Video::query()->where('guid', $guid)->firstOrFail();
}

/** Video đã transcode xong với file HLS giả trên đĩa. */
function vlFinishedVideo(): string
{
    $guid = vlCreateVideo();
    $dir = app(VideoLabStorage::class)->hlsDir($guid);
    mkdir($dir.'/360p', 0775, true);
    file_put_contents($dir.'/playlist.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=896000,RESOLUTION=640x360\n360p/index.m3u8\n");
    file_put_contents($dir.'/360p/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
    file_put_contents($dir.'/360p/seg_00000.ts', 'TSDATA');
    Video::query()->where('guid', $guid)->update(['status' => Video::FINISHED, 'length_seconds' => 10, 'finished_at' => now()]);

    return $guid;
}

function vlPlaybackUrl(string $guid, ?string $ip = null, int $ttl = 900): string
{
    $asset = VideoAsset::factory()->make(['provider_video_id' => $guid]);
    $info = app(InternalVideoProvider::class)->playback($asset, new PlaybackContext(1, $ip, $ttl));

    // đổi origin công khai thành host test
    return preg_replace('#^https?://[^/]+#', 'http://'.config('videolab.host'), $info->url);
}
