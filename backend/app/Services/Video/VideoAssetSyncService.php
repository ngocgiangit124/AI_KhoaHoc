<?php

namespace App\Services\Video;

use App\Enums\VideoAssetStatus;
use App\Models\Lesson;
use App\Models\VideoAsset;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Support\Facades\DB;

/**
 * Đồng bộ `video_assets` từ trạng thái THẬT của nhà cung cấp (getVideo qua API có xác thực — webhook không được
 * tin). Chỉ tiến về phía trước; `ready`/`failed` là cuối. Dùng chung cho webhook, job, `videos:check-stuck`.
 */
class VideoAssetSyncService
{
    public function __construct(private readonly VideoProviderManager $providers) {}

    /**
     * @throws VideoProviderException nhà cung cấp lỗi/không khả dụng (người gọi quyết định thử lại)
     */
    public function sync(VideoAsset $asset): VideoAsset
    {
        $provider = $this->providers->driver($asset->provider);

        try {
            $remote = $provider->getVideo($asset->provider_video_id);
        } catch (VideoNotFoundException) {
            // Nhà cung cấp có thể trả 404 tạm (nhất quán muộn): chỉ đánh failed khi asset đã đủ cũ.
            if ($asset->updated_at !== null && $asset->updated_at->gt(now()->subMinutes((int) config('video.stuck.sync_after_minutes')))) {
                return $asset;
            }

            return $this->markFailed($asset, 'Video không còn tồn tại ở nhà cung cấp.');
        }

        return $this->apply($asset, $remote);
    }

    /**
     * Đồng bộ rồi đánh dấu `failed` nếu vẫn chưa xong mà đã quá hạn (upload dở / xử lý treo).
     */
    public function reconcile(VideoAsset $asset): VideoAsset
    {
        $asset = $this->sync($asset);

        if ($this->isStuck($asset)) {
            return $this->markFailed($asset, $asset->status === VideoAssetStatus::Processing
                ? 'Xử lý video quá thời gian cho phép.'
                : 'Tải video lên không hoàn tất trong thời gian cho phép.');
        }

        return $asset;
    }

    public function isStuck(VideoAsset $asset): bool
    {
        return match ($asset->status) {
            VideoAssetStatus::Created, VideoAssetStatus::Uploading => $asset->created_at !== null
                && $asset->created_at->lte(now()->subMinutes((int) config('video.stuck.upload_timeout_minutes'))),
            VideoAssetStatus::Processing => $asset->updated_at !== null
                && $asset->updated_at->lte(now()->subMinutes((int) config('video.stuck.processing_timeout_minutes'))),
            default => false,
        };
    }

    public function markFailed(VideoAsset $asset, string $message): VideoAsset
    {
        return $this->apply($asset, new ProviderVideo($asset->provider_video_id, VideoAssetStatus::Failed, null, $message));
    }

    private function apply(VideoAsset $asset, ProviderVideo $remote): VideoAsset
    {
        return DB::transaction(function () use ($asset, $remote): VideoAsset {
            $locked = VideoAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return $asset; // đã bị dọn đồng thời
            }

            if ($this->isFinal($locked->status) || $this->rank($remote->status) < $this->rank($locked->status)) {
                return $locked;
            }

            $changed = $remote->status !== $locked->status;

            if ($remote->status === VideoAssetStatus::Ready) {
                $duration = max(0, (int) $remote->durationSeconds);
                $locked->forceFill(['status' => VideoAssetStatus::Ready, 'duration_seconds' => $duration, 'error_message' => null])->save();

                // Cập nhật có điều kiện: bài đã đổi sang video khác/nguồn khác thì không ghi đè thời lượng.
                Lesson::query()->where('video_asset_id', $locked->getKey())->update(['duration_seconds' => $duration]);
            } elseif ($remote->status === VideoAssetStatus::Failed) {
                $locked->forceFill([
                    'status' => VideoAssetStatus::Failed,
                    'error_message' => mb_substr(trim(strip_tags($remote->errorMessage ?? 'Xử lý video thất bại.')), 0, 500) ?: 'Xử lý video thất bại.',
                ])->save();
            } elseif ($changed) {
                $locked->forceFill(['status' => $remote->status])->save();
            }

            return $locked;
        });
    }

    private function isFinal(VideoAssetStatus $status): bool
    {
        return $status === VideoAssetStatus::Ready || $status === VideoAssetStatus::Failed;
    }

    private function rank(VideoAssetStatus $status): int
    {
        return match ($status) {
            VideoAssetStatus::Created => 0,
            VideoAssetStatus::Uploading => 1,
            VideoAssetStatus::Processing => 2,
            VideoAssetStatus::Ready, VideoAssetStatus::Failed => 3,
        };
    }
}
