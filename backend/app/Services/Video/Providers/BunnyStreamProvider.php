<?php

namespace App\Services\Video\Providers;

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Contracts\VerifiesWebhookRequest;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Data\PlaybackInfo;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Data\UploadTarget;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\Bunny\BunnySigner;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter 'bunny' (US-021, ADR-002): Bunny Stream.
 * - API quản lý: `{api_base}/library/{libraryId}/videos[/{guid}]`, header `AccessKey` (khoá API của thư viện).
 * - Upload: TUS trực tiếp từ trình duyệt lên `tus_endpoint`, chữ ký ở BunnySigner (không đi qua server mình).
 * - Phát: HLS qua CDN `cdn_host`, token Bunny ký theo thư mục `/{guid}/` (BunnySigner).
 * - Webhook Bunny KHÔNG có chữ ký: chỉ lấy `VideoGuid`, trạng thái luôn lấy lại bằng getVideo().
 * Mọi HTTP có timeout ≤ 10s. Khoá không bao giờ nằm trong exception/log; thông điệp lỗi do ta tự viết, không chép
 * nguyên văn nội dung phản hồi/ngoại lệ HTTP (có thể chứa URL).
 *
 * Mã trạng thái Bunny (đối chiếu tài liệu hiện hành): 0 Created, 1 Uploaded, 2 Processing, 3 Transcoding,
 * 4 Finished, 5 Error, 6 UploadFailed; mã khác (7 JIT segmenting, 8...) coi là đang xử lý.
 */
class BunnyStreamProvider implements VerifiesWebhookRequest, VideoProvider
{
    private const MAX_UPLOAD_TTL = 21600; // 6h

    /** Modifier `D`: `$` không được khớp trước ký tự xuống dòng cuối. */
    private const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** Biến môi trường tương ứng khoá cấu hình bắt buộc (để báo lỗi nêu đúng tên biến, không in giá trị). */
    public const REQUIRED = [
        'library_id' => 'BUNNY_LIBRARY_ID',
        'api_key' => 'BUNNY_API_KEY',
        'cdn_host' => 'BUNNY_CDN_HOST',
        'token_key' => 'BUNNY_TOKEN_KEY',
    ];

    public function name(): string
    {
        return 'bunny';
    }

    public function libraryId(): string
    {
        return $this->required('library_id');
    }

    /** @throws VideoProviderException nêu tên biến env còn thiếu (không in giá trị) */
    public function assertConfigured(): void
    {
        $missing = [];

        foreach (self::REQUIRED as $key => $env) {
            $value = config("video.providers.bunny.{$key}");

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $env;
            }
        }

        if ($missing !== []) {
            throw new VideoProviderException('Bunny Stream chưa cấu hình đủ: thiếu '.implode(', ', $missing).'.');
        }

        $this->cdnBase(); // ném nếu CDN host sai định dạng
    }

    public function createVideo(string $title, ?int $maxBytes = null): ProviderVideo
    {
        $response = $this->send(fn (PendingRequest $http) => $http->asJson()->post($this->videosUrl(), [
            'title' => mb_substr($title, 0, 255),
        ]));

        if (! $response->successful()) {
            throw new VideoProviderException('Bunny từ chối tạo video (HTTP '.$response->status().').');
        }

        return $this->toProviderVideo($response->json());
    }

    /**
     * T37-1: đẩy tệp gốc từ server lên video đã tạo (`PUT .../videos/{guid}`, thân nhị phân). Dùng stream nên không
     * nạp cả tệp vào RAM; timeout riêng cho tệp lớn (`video.migration.upload_timeout_seconds`), KHÔNG dùng cho
     * luồng học sinh.
     *
     * @throws VideoProviderException
     */
    public function uploadSource(string $providerVideoId, string $path): void
    {
        $this->assertGuid($providerVideoId);

        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new VideoProviderException('Không đọc được tệp gốc để tải lên Bunny.');
        }

        try {
            $response = $this->send(
                fn (PendingRequest $http) => $http->withBody(Utils::streamFor($handle), 'application/octet-stream')
                    ->put($this->videosUrl().'/'.$providerVideoId),
                max(60, (int) config('video.migration.upload_timeout_seconds', 3600)),
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($response->status() === 404) {
            throw new VideoNotFoundException('Video không tồn tại ở Bunny.');
        }

        if (! $response->successful()) {
            throw new VideoProviderException('Bunny từ chối tệp tải lên (HTTP '.$response->status().').');
        }
    }

    public function uploadTarget(ProviderVideo $video, int $ttlSeconds): UploadTarget
    {
        $this->assertGuid($video->guid);

        $ttl = max(60, min($ttlSeconds, self::MAX_UPLOAD_TTL));
        $expires = now()->toImmutable()->addSeconds($ttl);
        $library = $this->libraryId();

        return new UploadTarget(
            'tus',
            $this->required('tus_endpoint'),
            [
                'AuthorizationSignature' => BunnySigner::uploadSignature($library, $this->required('api_key'), $expires->getTimestamp(), $video->guid),
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
            throw new VideoProviderException('Bunny trả HTTP '.$response->status().'.');
        }

        return $this->toProviderVideo($response->json());
    }

    public function playback(VideoAsset $asset, PlaybackContext $ctx): PlaybackInfo
    {
        $guid = (string) $asset->provider_video_id;
        $this->assertGuid($guid);

        $expires = now()->toImmutable()->addSeconds(max(1, $ctx->ttlSeconds));

        return new PlaybackInfo(
            'hls',
            BunnySigner::hlsUrl($this->cdnBase(), $guid, $this->required('token_key'), $expires->getTimestamp(), $ctx->ip),
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

        throw new VideoProviderException('Bunny không xoá được video (HTTP '.$response->status().').');
    }

    /** Bí mật `?k=` trên URL webhook; so hằng thời gian; chưa cấu hình thì từ chối (đóng an toàn). */
    public function verifyWebhookRequest(Request $request): bool
    {
        $expected = config('video.providers.bunny.webhook_token');
        $given = $request->query('k');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    public function parseWebhook(Request $request): ?string
    {
        $guid = $request->json('VideoGuid');

        return is_string($guid) && preg_match(self::GUID_PATTERN, $guid) === 1 ? mb_strtolower($guid) : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, int $timeout = 10): Response
    {
        $http = Http::withHeaders(['AccessKey' => $this->required('api_key')])
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(5)
            ->withoutRedirecting(); // AccessKey không được đi theo redirect sang host khác

        try {
            $response = $call($http);
        } catch (HttpClientException) {
            // Không dùng message của ngoại lệ gốc (có thể chứa URL).
            throw new VideoProviderException('Không kết nối được Bunny Stream.');
        }

        if ($response->redirect()) {
            throw new VideoProviderException('Bunny trả HTTP '.$response->status().' (chuyển hướng bị từ chối).');
        }

        if ($response->status() === 401) {
            Log::error('Bunny từ chối khoá API (kiểm tra BUNNY_API_KEY và BUNNY_LIBRARY_ID).');

            throw new VideoProviderException('Bunny từ chối khoá truy cập.');
        }

        if ($response->serverError()) {
            throw new VideoProviderException('Bunny lỗi (HTTP '.$response->status().').');
        }

        return $response;
    }

    private function videosUrl(): string
    {
        return rtrim($this->required('api_base'), '/').'/library/'.rawurlencode($this->libraryId()).'/videos';
    }

    /** `BUNNY_CDN_HOST` là hostname trần hoặc https://hostname; http:// bị từ chối. */
    private function cdnBase(): string
    {
        $raw = trim($this->required('cdn_host'));

        if (preg_match('#^https?://#i', $raw) === 1 && preg_match('#^https://#i', $raw) !== 1) {
            throw new VideoProviderException('BUNNY_CDN_HOST phải dùng https.');
        }

        $host = rtrim((string) preg_replace('#^https://#i', '', $raw), '/');

        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d{1,5})?$/', $host) !== 1) {
            throw new VideoProviderException('BUNNY_CDN_HOST không hợp lệ (chỉ nhận tên miền, không kèm đường dẫn).');
        }

        return 'https://'.$host;
    }

    private function required(string $key): string
    {
        $value = config("video.providers.bunny.{$key}");

        if (! is_string($value) || trim($value) === '') {
            $env = self::REQUIRED[$key] ?? 'BUNNY_'.mb_strtoupper($key);

            throw new VideoProviderException("Bunny Stream chưa cấu hình [{$env}].");
        }

        return trim($value);
    }

    private function assertGuid(string $guid): void
    {
        if (preg_match(self::GUID_PATTERN, $guid) !== 1) {
            throw new VideoProviderException('Mã video không hợp lệ.');
        }
    }

    /**
     * @param  mixed  $data
     */
    private function toProviderVideo($data): ProviderVideo
    {
        if (! is_array($data) || ! is_string($data['guid'] ?? null)) {
            throw new VideoProviderException('Phản hồi Bunny không hợp lệ.');
        }

        $this->assertGuid($data['guid']);
        $code = $data['status'] ?? 0;
        $code = is_int($code) || (is_string($code) && ctype_digit($code)) ? (int) $code : -1;

        $status = match ($code) {
            0 => VideoAssetStatus::Created,
            4 => VideoAssetStatus::Ready,
            5, 6 => VideoAssetStatus::Failed,
            default => VideoAssetStatus::Processing, // 1, 2, 3 và mọi mã chưa biết (7, 8...): không bao giờ tự thành failed
        };

        $duration = null;

        if ($status === VideoAssetStatus::Ready) {
            $length = $data['length'] ?? null;

            if (! is_int($length) && ! is_float($length) && ! (is_string($length) && is_numeric($length))) {
                throw new VideoProviderException('Phản hồi Bunny thiếu thời lượng video.');
            }

            $duration = max(0, (int) round((float) $length));
        }

        $error = match ($code) {
            5 => 'Bunny không mã hoá được video này. Vui lòng chọn tệp khác.',
            6 => 'Tải video lên không hoàn tất. Vui lòng chọn tệp khác.',
            default => null,
        };

        return new ProviderVideo(mb_strtolower($data['guid']), $status, $duration, $error);
    }
}
