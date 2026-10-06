<?php

namespace App\Console\Commands;

use App\Services\Content\OrphanImagePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImagesPruneOrphansCommand extends Command
{
    protected $signature = 'images:prune-orphans {--dry-run : Chỉ đếm, không xoá}';

    protected $description = 'Xoá ảnh trên disk uploads cũ hơn 24 giờ mà không còn được tham chiếu (thumbnail khóa, avatar giáo viên)';

    public function handle(OrphanImagePruner $pruner): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = $pruner->prune($dry);

        $line = "Ảnh đã quét: {$stats['scanned']}, mồ côi: {$stats['found']}, đã xoá: {$stats['deleted']}, lỗi: {$stats['failed']}, bỏ qua symlink: {$stats['skipped_links']}".($dry ? ' (dry-run)' : '');
        $this->info($line);
        Log::info('images:prune-orphans', $stats + ['dry_run' => $dry]);

        return self::SUCCESS;
    }
}
