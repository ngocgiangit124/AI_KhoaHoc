<?php

namespace App\Services\Video;

use App\Models\VideoAsset;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Webhook video (ADR-002 §2): payload CHỈ cung cấp `provider_video_id`; trạng thái/thời lượng lấy lại bằng
 * getVideo() có xác thực. `{provider}` trên URL phải khớp `video_assets.provider` của asset.
 */
class VideoWebhookService
{
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

        $videoId = $provider->parseWebhook($request);

        if ($videoId === null) {
            Log::info('Webhook video không hợp lệ', ['provider' => $providerName]);

            return true;
        }

        $asset = VideoAsset::query()->where('provider', $provider->name())->where('provider_video_id', $videoId)->first();

        if ($asset !== null) {
            $this->sync->sync($asset);
        }

        return true; // không phân biệt asset có/không để không lộ dữ liệu
    }
}
