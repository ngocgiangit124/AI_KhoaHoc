<?php

namespace App\Console\Commands;

use App\Enums\VideoAssetStatus;
use App\Jobs\SyncVideoAssetStatusJob;
use App\Models\VideoAsset;
use Illuminate\Console\Command;

class VideosCheckStuckCommand extends Command
{
    protected $signature = 'videos:check-stuck {--dry-run : Chỉ liệt kê, không đồng bộ}';

    protected $description = 'Đồng bộ trạng thái video chưa xong từ nhà cung cấp; đánh dấu failed nếu upload/xử lý treo quá hạn';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('video.stuck.sync_after_minutes'));
        $count = 0;

        VideoAsset::query()
            ->whereIn('status', [VideoAssetStatus::Created->value, VideoAssetStatus::Uploading->value, VideoAssetStatus::Processing->value])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($assets) use (&$count): void {
                foreach ($assets as $asset) {
                    $count++;

                    if (! $this->option('dry-run')) {
                        SyncVideoAssetStatusJob::dispatch($asset->getKey());
                    }
                }
            });

        $this->info(($this->option('dry-run') ? 'Cần đồng bộ: ' : 'Đã xếp hàng đồng bộ: ').$count);

        return self::SUCCESS;
    }
}
