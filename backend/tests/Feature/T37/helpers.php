<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Providers\BunnyStreamProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T11/helpers.php';

const BZ_LIB = '777';
const BZ_API_KEY = 'bz-api-key-SECRET-0001';
const BZ_TOKEN_KEY = 'bz-token-key-SECRET-0002';
const BZ_CDN = 'vz-test.b-cdn.net';
const BZ_WEBHOOK_TOKEN = 'bz-webhook-token-SECRET-0003-0123456789abcdef';
const BZ_GUID = '11111111-2222-3333-4444-555555555555';

/** Bật Bunny làm mặc định với cấu hình hợp lệ (ghi đè được từng khoá). */
function bzConfigure(array $over = [], array $enabled = ['bunny']): void
{
    config([
        'video.provider' => 'bunny',
        'video.enabled_providers' => $enabled,
        'video.providers.bunny' => array_merge([
            'library_id' => BZ_LIB,
            'api_key' => BZ_API_KEY,
            'cdn_host' => BZ_CDN,
            'token_key' => BZ_TOKEN_KEY,
            'webhook_token' => BZ_WEBHOOK_TOKEN,
            'api_base' => 'https://video.bunnycdn.com',
            'tus_endpoint' => 'https://video.bunnycdn.com/tusupload',
        ], $over),
    ]);
}

function bzProvider(): BunnyStreamProvider
{
    return app(BunnyStreamProvider::class);
}

/** Phản hồi VideoModel của Bunny. */
function bzVideoBody(string $guid = BZ_GUID, int $status = 0, int|float|null $length = 0): array
{
    return array_filter(['guid' => $guid, 'videoLibraryId' => (int) BZ_LIB, 'title' => 'x', 'status' => $status, 'length' => $length], fn ($v) => $v !== null);
}

/** Http::fake định tuyến theo method + đường dẫn tới API Bunny. */
function bzFakeApi(array $routes): void
{
    Http::swap(new Factory); // mỗi lần gọi là một bộ fake mới (fake cũ sẽ thắng nếu cộng dồn)
    Http::fake(function ($request) use ($routes) {
        $key = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        if (! array_key_exists($key, $routes)) {
            return Http::response(['message' => 'unexpected '.$key], 599);
        }

        $r = $routes[$key];

        return $r instanceof Closure ? $r($request) : $r;
    });
}

function bzVideosPath(string $suffix = ''): string
{
    return '/library/'.BZ_LIB.'/videos'.$suffix;
}

/** Bài có asset Bunny đang xử lý. */
function bzLessonWithAsset(VideoAssetStatus $status = VideoAssetStatus::Uploading, string $guid = BZ_GUID): array
{
    [$course, $chapter, $lesson] = vvContentSet();
    $asset = VideoAsset::factory()->create([
        'provider' => 'bunny', 'provider_library_id' => BZ_LIB, 'provider_video_id' => $guid, 'status' => $status, 'lesson_id' => $lesson->id,
    ]);
    $lesson->forceFill(['video_source' => 'upload', 'video_asset_id' => $asset->id])->save();

    return [$course, $lesson->fresh(), $asset];
}

/** Webhook Bunny với bí mật `?k=` trên URL (đặt $token = null để bỏ, hoặc chuỗi khác để thử sai). */
function bzWebhook(array $payload, ?string $token = BZ_WEBHOOK_TOKEN): TestResponse
{
    $query = $token === null ? '' : '?k='.rawurlencode($token);

    return test()->postJson(vvApiUrl('/webhooks/video/bunny').$query, $payload, ['Accept' => 'application/json']);
}

/** Không chuỗi nào chứa khoá/bí mật/URL nội bộ. */
function bzAssertNoSecret(string $text): void
{
    expect($text)->not->toContain(BZ_API_KEY)->and($text)->not->toContain(BZ_TOKEN_KEY)
        ->and($text)->not->toContain(BZ_WEBHOOK_TOKEN)->and($text)->not->toContain('AccessKey');
}
