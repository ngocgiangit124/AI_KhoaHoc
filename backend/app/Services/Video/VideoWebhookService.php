<?php

namespace App\Services\Video;

use App\Enums\VideoAssetStatus;
use App\Jobs\SyncVideoAssetStatusJob;
use App\Models\VideoAsset;
use App\Services\Video\Contracts\VerifiesWebhookRequest;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Webhook video (ADR-002 §2): payload CHỈ cung cấp `provider_video_id`; trạng thái/thời lượng lấy lại bằng
 * getVideo() có xác thực. `{provider}` trên URL phải khớp `video_assets.provider` của asset.
 */
class VideoWebhookService
{
    private const RESYNC_DELAY_SECONDS = 15;

    private const DEDUPE_SECONDS = 10; // ngắn: webhook "Finished" đến sát webhook trước không được bị bỏ quá lâu (sweep 15 phút là lưới cuối)

    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly VideoAssetSyncService $sync,
    ) {}

    /**
     * @return bool false nếu provider không nằm trong allowlist/chưa cấu hình (→ 404)
     *
     * @throws VideoProviderException nhà cung cấp lỗi khi xác minh (→ 503 để họ gửi lại)
     */
    public function handle(string $providerName, Request $request): bool
    {
        try {
            $provider = $this->providers->driver($providerName);
        } catch (InvalidArgumentException) {
            return false;
        } catch (VideoProviderException) {
            return false;
        }

        // S1: bí mật trên URL (Bunny không ký webhook). Sai/thiếu → 404, không gọi mạng.
        if ($provider instanceof VerifiesWebhookRequest && ! $provider->verifyWebhookRequest($request)) {
            return false;
        }

        $videoId = $provider->parseWebhook($request);

        if ($videoId === null) {
            Log::info('Webhook video không hợp lệ', ['provider' => $providerName]);

            return true;
        }

        $asset = VideoAsset::query()->where('provider', $provider->name())->where('provider_video_id', $videoId)->first();

        // S1: asset đã ở trạng thái cuối thì không gọi nhà cung cấp; webhook trùng của cùng asset trong cửa sổ ngắn chỉ
        // gọi một lần. Lỗi khi xác minh thì nhả khoá để lần gửi lại của nhà cung cấp được xử lý.
        if ($asset !== null && ! in_array($asset->status, [VideoAssetStatus::Ready, VideoAssetStatus::Failed], true)) {
            $key = 'video-webhook:'.$asset->getKey();

            if (Cache::add($key, 1, self::DEDUPE_SECONDS)) {
                try {
                    $this->sync->sync($asset);
                } catch (VideoProviderException $e) {
                    Cache::forget($key);

                    throw $e;
                }
            } else {
                // R-2: webhook sát nhau bị gom vẫn có thể là "Finished": đồng bộ lại sau một nhịp (job unique, chạy ngoài FPM)
                // để trạng thái cuối không phải chờ lần quét 15 phút.
                SyncVideoAssetStatusJob::dispatch($asset->getKey())->delay(now()->addSeconds(self::RESYNC_DELAY_SECONDS));
            }
        }

        return true; // không phân biệt asset có/không để không lộ dữ liệu
    }
}
