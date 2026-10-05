<?php

namespace App\Services\Video\Providers;

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Data\PlaybackInfo;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Data\UploadTarget;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Nhà cung cấp giả CHỈ cho local/testing (VideoServiceProvider; ProductionConfigGuard cấm ở production).
 * Trạng thái nằm trong bộ nhớ tiến trình: test điều khiển bằng `setStatus()`, `failNext()`.
 */
class FakeVideoProvider implements VideoProvider
{
    /** @var array<string, ProviderVideo> */
    private array $videos = [];

    /** @var list<string> */
    public array $deleted = [];

    private bool $unavailable = false;

    /** Test: chạy ngay sau createVideo (mô phỏng thay đổi đồng thời trong lúc gọi HTTP). */
    public ?\Closure $afterCreate = null;

    public function name(): string
    {
        return 'fake';
    }

    public function createVideo(string $title): ProviderVideo
    {
        $this->guard();
        $video = new ProviderVideo((string) Str::uuid(), VideoAssetStatus::Created);
        $this->videos[$video->guid] = $video;

        if ($this->afterCreate !== null) {
            ($this->afterCreate)($video);
        }

        return $video;
    }

    public function uploadTarget(ProviderVideo $video, int $ttlSeconds): UploadTarget
    {
        $this->guard();
        $expires = now()->toImmutable()->addSeconds($ttlSeconds);
        $library = (string) config('video.library_id');

        return new UploadTarget(
            'tus',
            (string) config('video.providers.fake.tus_endpoint'),
            [
                'AuthorizationSignature' => hash('sha256', $library.config('video.providers.fake.secret').$expires->getTimestamp().$video->guid),
                'AuthorizationExpire' => (string) $expires->getTimestamp(),
                'VideoId' => $video->guid,
                'LibraryId' => $library,
            ],
            $expires,
        );
    }

    public function getVideo(string $providerVideoId): ProviderVideo
    {
        $this->guard();

        return $this->videos[$providerVideoId] ?? throw new VideoNotFoundException('Video không tồn tại.');
    }

    public function playback(VideoAsset $asset, PlaybackContext $ctx): PlaybackInfo
    {
        $this->guard();
        $expires = now()->toImmutable()->addSeconds($ctx->ttlSeconds);
        $token = hash_hmac('sha256', '/'.$asset->provider_video_id.'/'.$expires->getTimestamp().($ctx->ip ?? ''), (string) config('video.providers.fake.secret'));

        return new PlaybackInfo(
            'hls',
            rtrim((string) config('video.providers.fake.cdn_base'), '/')."/{$token}/{$expires->getTimestamp()}/{$asset->provider_video_id}/playlist.m3u8",
            $expires,
        );
    }

    public function deleteVideo(string $providerVideoId): void
    {
        $this->guard();
        unset($this->videos[$providerVideoId]);
        $this->deleted[] = $providerVideoId;
    }

    public function parseWebhook(Request $request): ?string
    {
        $guid = $request->json('VideoGuid');

        return is_string($guid) && preg_match('/^[0-9a-f-]{36}$/', $guid) === 1 ? $guid : null;
    }

    // ---- Điều khiển cho test ----

    public function setStatus(string $guid, VideoAssetStatus $status, ?int $durationSeconds = null, ?string $error = null): void
    {
        $this->videos[$guid] = new ProviderVideo($guid, $status, $durationSeconds, $error);
    }

    public function forget(string $guid): void
    {
        unset($this->videos[$guid]);
    }

    public function has(string $guid): bool
    {
        return isset($this->videos[$guid]);
    }

    public function setUnavailable(bool $unavailable = true): void
    {
        $this->unavailable = $unavailable;
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new VideoProviderException('Nhà cung cấp video không khả dụng.');
        }
    }
}
