<?php

namespace App\Jobs;

use App\Models\VideoAsset;
use App\Services\Video\VideoAssetSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** Đồng bộ 1 asset từ nhà cung cấp (+ đánh dấu failed nếu treo quá hạn). Lỗi nhà cung cấp → thử lại có backoff. */
class SyncVideoAssetStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return (string) $this->videoAssetId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly int $videoAssetId) {}

    public function handle(VideoAssetSyncService $sync): void
    {
        $asset = VideoAsset::query()->find($this->videoAssetId);

        if ($asset !== null) {
            try {
                $sync->reconcile($asset);
            } catch (InvalidArgumentException $e) {
                // Provider của asset không còn trong allowlist: thử lại vô ích.
                Log::warning('Bỏ qua đồng bộ video: provider không còn được bật', ['asset_id' => $asset->getKey(), 'provider' => $asset->provider]);
            }
        }
    }
}
