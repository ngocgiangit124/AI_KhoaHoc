<?php

namespace App\VideoLab\Console;

use App\VideoLab\Models\Video;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Dọn VideoLab (ADR-002 §3): upload dở > 24h (hoặc quá hạn chữ ký) → status 6 + xoá file tạm;
 * file gốc của video đã xong > 7 ngày → xoá (trừ khi `videolab.storage.keep_source`);
 * file gốc của video lỗi (status 5) quá `failed_source_retention_hours` → xoá.
 */
class VideoLabCleanupCommand extends Command
{
    protected $signature = 'videolab:cleanup {--dry-run : Chỉ liệt kê thư mục mồ côi, không xoá}';

    protected $description = 'Dọn upload dở và file gốc cũ của VideoLab';

    public function handle(VideoLabStorage $storage): int
    {
        $stale = 0;
        $sources = 0;

        $incomingCutoff = now()->subHours((int) config('videolab.storage.incoming_retention_hours'));

        Video::query()
            ->where('status', Video::CREATED)
            ->where(fn ($q) => $q->where('created_at', '<=', $incomingCutoff)->orWhere('upload_expires_at', '<=', now()->subHour()))
            ->where('created_at', '<=', now()->subHour())
            ->each(function (Video $video) use ($storage, &$stale): void {
                File::delete($storage->incoming($video->guid));
                $video->forceFill(['status' => Video::UPLOAD_FAILED, 'error' => 'Tải lên không hoàn tất.'])->save();
                $stale++;
            });

        if (! config('videolab.storage.keep_source')) {
            $sourceCutoff = now()->subDays((int) config('videolab.storage.source_retention_days'));

            Video::query()
                ->where('status', Video::FINISHED)
                ->where('finished_at', '<=', $sourceCutoff)
                ->whereNotNull('source_path')
                ->each(function (Video $video) use ($storage, &$sources): void {
                    File::delete($storage->source($video->guid));
                    $video->forceFill(['source_path' => null])->save();
                    $sources++;
                });
        }

        // Cụm 2 L3: video lỗi (status 5) giữ `source/{guid}.bin` quá `failed_source_retention_hours` thì xoá (file độc/hỏng
        // không cần giữ; kể cả khi `keep_source` bật).
        $failedCutoff = now()->subHours((int) config('videolab.storage.failed_source_retention_hours', 24));
        $failedSources = 0;

        Video::query()
            ->where('status', Video::ERROR)
            ->where('updated_at', '<=', $failedCutoff)
            ->each(function (Video $video) use ($storage, &$failedSources): void {
                $path = $storage->source($video->guid);

                if (is_file($path) && ! $this->option('dry-run')) {
                    File::delete($path);
                    $video->forceFill(['source_path' => null])->save();
                    $failedSources++;
                } elseif (is_file($path)) {
                    $failedSources++;
                }
            });

        $orphans = $this->sweepOrphans($storage);

        $this->info("Upload dở đã dọn: {$stale}; file gốc đã xoá: {$sources}; file gốc video lỗi: {$failedSources}; mồ côi ".($this->option('dry-run') ? 'tìm thấy' : 'đã xoá').": {$orphans}");

        return self::SUCCESS;
    }

    /**
     * Quét `hls/*` và `source/*` không còn bản ghi `vl_videos` (vd video bị xoá giữa lúc transcode). Chỉ xoá mục cũ hơn
     * `videolab.storage.orphan_grace_minutes` (mặc định 60) để không đụng job đang chạy.
     */
    private function sweepOrphans(VideoLabStorage $storage): int
    {
        $cutoff = now()->subMinutes((int) config('videolab.storage.orphan_grace_minutes', 60))->getTimestamp();
        $dry = (bool) $this->option('dry-run');
        $count = 0;

        foreach (['hls', 'source'] as $sub) {
            $base = $storage->root().'/'.$sub;
            $entries = [];

            foreach (File::glob($base.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
                $name = basename($path);
                $guid = $sub === 'hls' ? preg_replace('/^\.work-/', '', $name) : preg_replace('/\.bin$/', '', $name);

                if (is_string($guid) && Video::isGuid($guid) && ! is_link($path)) {
                    $entries[$guid][] = $path;
                }
            }

            if ($entries === []) {
                continue;
            }

            $existing = Video::query()->whereIn('guid', array_keys($entries))->pluck('guid')->all();

            foreach (array_diff_key($entries, array_flip($existing)) as $paths) {
                foreach ($paths as $path) {
                    if ((int) filemtime($path) > $cutoff) {
                        continue;
                    }

                    if (! $dry) {
                        is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
                    }

                    $count++;
                }
            }
        }

        return $count;
    }
}
