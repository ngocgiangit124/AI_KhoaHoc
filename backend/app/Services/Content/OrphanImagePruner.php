<?php

namespace App\Services\Content;

use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Dọn ảnh mồ côi trên disk `uploads`: file `{uuid}.webp` cũ hơn `grace_hours` mà KHÔNG còn được tham chiếu bởi
 * `courses.thumbnail_path` (kể cả khóa đã xoá mềm), `teacher_profiles.avatar_path` hay `users.avatar_path` (còn tới khi
 * xoá cột, T36-1). Ảnh mồ côi sinh ra khi tiến trình chết giữa "ghi file" và "commit" (ảnh đã tải nhưng lưu hồ sơ lỗi).
 *
 * Chỉ đụng tên khớp mẫu do `ImageUploadService` sinh (không bao giờ xoá file lạ). Kiểm lại tham chiếu ngay trước khi
 * xoá từng file; file mới (trong thời gian ân hạn) không bao giờ bị xoá.
 */
class OrphanImagePruner
{
    public const GRACE_HOURS = 24;

    private const NAME_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$/';

    /**
     * @return array{scanned: int, found: int, deleted: int, failed: int, skipped_links: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $stats = ['scanned' => 0, 'found' => 0, 'deleted' => 0, 'failed' => 0, 'skipped_links' => 0];
        $disk = Storage::disk(ImageUploadService::DISK);
        $root = rtrim($disk->path(''), '/');
        $cutoff = now()->subHours(self::GRACE_HOURS)->getTimestamp();

        if (! is_dir($root)) {
            return $stats;
        }

        // Duyệt bằng DirectoryIterator thay vì `$disk->files()`: Flysystem ném lỗi và dừng cả lượt khi gặp symlink (I7).
        // Symlink bị bỏ qua (đếm riêng, ghi log), không bao giờ theo link hay xoá đích của link.
        foreach (new DirectoryIterator($root) as $entry) {
            $name = $entry->getFilename();

            if ($entry->isDot() || preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }

            if ($entry->isLink()) {
                $stats['skipped_links']++;
                Log::warning('images:prune-orphans bỏ qua symlink trong uploads.', ['file' => $name]);

                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            $stats['scanned']++;

            try {
                if ($entry->getMTime() > $cutoff || $this->isReferenced($name)) {
                    continue;
                }

                $stats['found']++;

                if ($dryRun) {
                    continue;
                }

                $disk->delete($name) ? $stats['deleted']++ : $stats['failed']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('images:prune-orphans không xử lý được một file.', ['file' => $name, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    private function isReferenced(string $name): bool
    {
        return DB::table('courses')->where('thumbnail_path', $name)->exists()
            || DB::table('teacher_profiles')->where('avatar_path', $name)->exists()
            || DB::table('users')->where('avatar_path', $name)->exists();
    }
}
