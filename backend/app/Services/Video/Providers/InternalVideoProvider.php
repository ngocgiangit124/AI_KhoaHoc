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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Adapter 'internal' (ADR-002): nói chuyện với module VideoLab qua HTTP (AccessKey) giống Bunny. KHÔNG import code
 * của module VideoLab (ranh giới module); công thức ký là bản sao có chủ đích:
 * - upload: HMAC-SHA256(library_id . expire . guid, api_key) (hex);
 * - phát:   base64url(HMAC-SHA256(token_key, "/{guid}/" . expires . ip)) — ký theo thư mục như token_path của Bunny;
 * - webhook: header X-VideoLab-Signature = HMAC-SHA256(body, webhook_secret).
 * Mọi HTTP có timeout ≤ 10s. Message lỗi không chứa khoá.
 */
class InternalVideoProvider implements VideoProvider
{
    private const MAX_UPLOAD_TTL = 21600; // 6h (ADR-002 §3a.5)

    public function name(): string
    {
        return 'internal';
    }

    public function libraryId(): string
    {
        return (string) config('video.library_id');
    }

    public function createVideo(string $title, ?int $maxBytes = null): ProviderVideo
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->videosUrl(), array_filter([
            'title' => mb_substr($title, 0, 255),
            // T12-2: trần upload của video = kích thước đã khai (VideoLab kẹp thêm theo `video.max_upload_mb`).
            'max_bytes' => $maxBytes !== null && $maxBytes > 0 ? $maxBytes : null,
        ], static fn ($v) => $v !== null)));

        if ($response->status() !== 201) {
            throw new VideoProviderException('VideoLab từ chối tạo video (HTTP '.$response->status().').');
        }

        return $this->toProviderVideo($response->json());
    }

    public function uploadTarget(ProviderVideo $video, int $ttlSeconds): UploadTarget
    {
        $this->assertGuid($video->guid);

        $ttl = max(60, min($ttlSeconds, self::MAX_UPLOAD_TTL));
        $expires = now()->toImmutable()->addSeconds($ttl);
        $library = (string) config('video.library_id');

        return new UploadTarget(
            'tus',
            (string) config('videolab.public_url').'/videolab/tus',
            [
                'AuthorizationSignature' => hash_hmac('sha256', $library.$expires->getTimestamp().$video->guid, $this->secret('api_key')),
                'AuthorizationExpire' => (string) $expires->getTimestamp(),
                'VideoId' => $video->guid,
                'LibraryId' => $library,
            ],
            $expires,
        );
    }

    public function getVideo(string $providerVideoId): ProviderVideo
    {
        $this->assertGuid($providerVideoId);

        $response = $this->send(fn (PendingRequest $http) => $http->get($this->videosUrl().'/'.$providerVideoId));

        if ($response->status() === 404) {
            throw new VideoNotFoundException('Video không tồn tại.');
        }

        if (! $response->successful()) {
            throw new VideoProviderException('VideoLab trả HTTP '.$response->status().'.');
        }

        return $this->toProviderVideo($response->json());
    }

    public function playback(VideoAsset $asset, PlaybackContext $ctx): PlaybackInfo
    {
        $guid = $asset->provider_video_id;
        $this->assertGuid($guid);

        $expires = now()->toImmutable()->addSeconds(max(1, $ctx->ttlSeconds));
        $raw = hash_hmac('sha256', '/'.$guid.'/'.$expires->getTimestamp().($ctx->ip ?? ''), $this->secret('token_key'), true);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return new PlaybackInfo(
            'hls',
            (string) config('videolab.public_url')."/videolab/cdn/{$token}/{$expires->getTimestamp()}/{$guid}/playlist.m3u8",
            $expires,
        );
    }

    public function deleteVideo(string $providerVideoId): void
    {
        $this->assertGuid($providerVideoId);

        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->videosUrl().'/'.$providerVideoId));

        if ($response->status() === 404 || $response->successful()) {
            return;
        }

        throw new VideoProviderException('VideoLab không xoá được video (HTTP '.$response->status().').');
    }

    public function parseWebhook(Request $request): ?string
    {
        $signature = (string) $request->header('X-VideoLab-Signature', '');
        $secret = (string) config('videolab.webhook_secret');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return null;
        }

        $guid = $request->json('VideoGuid');

        return is_string($guid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid) === 1 ? $guid : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $http = Http::baseUrl((string) config('videolab.internal_url'))
            ->withHeaders(['AccessKey' => $this->secret('api_key'), 'Host' => (string) config('videolab.host')])
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(5);

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new VideoProviderException('Không kết nối được VideoLab.');
        }

        if ($response->serverError()) {
            throw new VideoProviderException('VideoLab lỗi (HTTP '.$response->status().').');
        }

        if ($response->status() === 401) {
            throw new VideoProviderException('VideoLab từ chối khoá truy cập (kiểm tra VIDEOLAB_API_KEY).');
        }

        return $response;
    }

    private function videosUrl(): string
    {
        return '/videolab/library/'.rawurlencode((string) config('video.library_id')).'/videos';
    }

    private function secret(string $key): string
    {
        $value = config("videolab.{$key}");

        if (! is_string($value) || $value === '') {
            throw new VideoProviderException("VideoLab chưa cấu hình khoá [{$key}].");
        }

        return $value;
    }

    private function assertGuid(string $guid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid) !== 1) {
            throw new VideoProviderException('Mã video không hợp lệ.');
        }
    }

    /**
     * @param  mixed  $data
     */
    private function toProviderVideo($data): ProviderVideo
    {
        if (! is_array($data) || ! is_string($data['guid'] ?? null)) {
            throw new VideoProviderException('Phản hồi VideoLab không hợp lệ.');
        }

        $this->assertGuid($data['guid']);
        $code = (int) ($data['status'] ?? 0);

        // Bunny-style 0–6 → trạng thái chuẩn hoá (ADR-002 §2).
        $status = match (true) {
            $code === 0 => ! empty($data['uploadStarted']) ? VideoAssetStatus::Uploading : VideoAssetStatus::Created,
            $code >= 1 && $code <= 3 => VideoAssetStatus::Processing,
            $code === 4 => VideoAssetStatus::Ready,
            default => VideoAssetStatus::Failed,
        };

        $error = $status === VideoAssetStatus::Failed
            ? (is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : 'Xử lý video thất bại.')
            : null;

        return new ProviderVideo(
            $data['guid'],
            $status,
            $status === VideoAssetStatus::Ready ? max(0, (int) ($data['length'] ?? 0)) : null,
            $error,
        );
    }
}
