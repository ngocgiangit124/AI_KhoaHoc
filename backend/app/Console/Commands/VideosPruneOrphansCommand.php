<?php

namespace App\Console\Commands;

use App\Services\Video\OrphanVideoPruner;
use Illuminate\Console\Command;

class VideosPruneOrphansCommand extends Command
{
    protected $signature = 'videos:prune-orphans {--dry-run : Chỉ đếm, không xoá}';

    protected $description = 'Xoá video_assets không còn bài học nào trỏ tới (và video tương ứng ở nhà cung cấp)';

    public function handle(OrphanVideoPruner $pruner): int
    {
        $stats = $pruner->prune((bool) $this->option('dry-run'));

        $this->info("Mồ côi: {$stats['found']}, đã xoá: {$stats['deleted']}, lỗi (thử lại lần sau): {$stats['failed']}, bỏ qua: {$stats['skipped']}");

        return self::SUCCESS;
    }
}
