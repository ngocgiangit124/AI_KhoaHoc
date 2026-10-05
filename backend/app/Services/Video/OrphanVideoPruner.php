<?php

namespace App\Services\Video;

use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dọn `video_assets` mồ côi: không còn bài (chưa xoá) nào có `video_asset_id` trỏ tới — do bài đổi sang
 * `none`/`external_link` (T09, kể cả khi asset đang processing), tải video khác đè lên, hoặc bài bị xoá mềm.
 * Xoá video ở nhà cung cấp TRƯỚC, rồi mới xoá dòng; nhà cung cấp lỗi → giữ dòng để lần chạy sau thử lại.
 */
class OrphanVideoPruner
{
    public function __construct(private readonly VideoProviderManager $providers) {}

    /**
     * @return array{found: int, deleted: int, failed: int, skipped: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $stats = ['found' => 0, 'deleted' => 0, 'failed' => 0, 'skipped' => 0];
        $cutoff = now()->subMinutes((int) config('video.orphan_grace_minutes'));

        VideoAsset::query()
            ->where('created_at', '<=', $cutoff)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('lessons')
                ->whereColumn('lessons.video_asset_id', 'video_assets.id')
                ->whereNull('lessons.deleted_at'))
            ->orderBy('id')
            ->chunkById(100, function ($assets) use (&$stats, $dryRun): void {
                foreach ($assets as $asset) {
                    $stats['found']++;

                    if ($dryRun) {
                        continue;
                    }

                    $stats[$this->pruneOne($asset)]++;
                }
            });

        return $stats;
    }

    /**
     * Khoá asset, kiểm lại còn mồ côi, rồi mới xoá ở nhà cung cấp và xoá dòng (giữ khoá trong lúc gọi — chỉ cron
     * dùng nên chấp nhận). Nhà cung cấp lỗi → giữ dòng, thử lại lần sau.
     *
     * @return 'deleted'|'failed'|'skipped'
     */
    private function pruneOne(VideoAsset $asset): string
    {
        return DB::transaction(function () use ($asset): string {
            $locked = VideoAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();

            if ($locked === null || DB::table('lessons')->where('video_asset_id', $locked->getKey())->whereNull('deleted_at')->exists()) {
                return 'skipped';
            }

            // Asset giữ chỗ hạn mức chưa từng có video ở nhà cung cấp: chỉ xoá dòng.
            if (! str_starts_with($locked->provider_video_id, VideoUploadService::PENDING_PREFIX)) {
                try {
                    $this->providers->driver($locked->provider)->deleteVideo($locked->provider_video_id);
                } catch (Throwable $e) {
                    Log::warning('Không xoá được video mồ côi ở nhà cung cấp', ['asset_id' => $locked->getKey(), 'provider' => $locked->provider, 'error' => $e->getMessage()]);

                    return 'failed';
                }
            }

            $locked->delete(); // khoá ngoại tự gỡ liên kết ở bài đã xoá mềm

            return 'deleted';
        });
    }
}
