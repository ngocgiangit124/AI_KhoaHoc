<?php

namespace App\VideoLab\Support;

use App\VideoLab\Models\Video;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Đường dẫn lưu trữ VideoLab. Thư mục tách bạch (ADR-002 §3a.4): incoming (đang upload), source (file gốc, KHÔNG
 * bao giờ phục vụ), hls (đầu ra, thứ duy nhất được phục vụ). `guid` luôn kiểm định dạng UUID trước khi nối đường dẫn.
 */
class VideoLabStorage
{
    public function root(): string
    {
        return rtrim((string) config('filesystems.disks.videolab.root'), '/');
    }

    public function incoming(string $guid): string
    {
        return $this->root().'/incoming/'.$this->guid($guid).'.part';
    }

    public function source(string $guid): string
    {
        return $this->root().'/source/'.$this->guid($guid).'.bin';
    }

    public function hlsDir(string $guid): string
    {
        return $this->root().'/hls/'.$this->guid($guid);
    }

    /** Thư mục làm việc tạm; đổi tên thành hlsDir khi xong để không bao giờ phục vụ nửa chừng. */
    public function hlsWorkDir(string $guid): string
    {
        return $this->root().'/hls/.work-'.$this->guid($guid);
    }

    public function ensureDirs(): void
    {
        foreach (['incoming', 'source', 'hls'] as $dir) {
            File::ensureDirectoryExists($this->root().'/'.$dir, 0775);
        }
    }

    /**
     * File HLS để phục vụ: `realpath` PHẢI nằm trong hls/{guid}/ (chống path traversal / symlink), nếu không → null.
     */
    public function resolveHlsFile(string $guid, string $path): ?string
    {
        $base = realpath($this->hlsDir($guid));

        if ($base === false) {
            return null;
        }

        $real = realpath($base.'/'.$path);

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public function deleteAll(string $guid): void
    {
        File::delete([$this->incoming($guid), $this->source($guid)]);
        File::deleteDirectory($this->hlsDir($guid));
        File::deleteDirectory($this->hlsWorkDir($guid));
    }

    private function guid(string $guid): string
    {
        if (! Video::isGuid($guid)) {
            throw new InvalidArgumentException('guid không hợp lệ.');
        }

        return $guid;
    }
}
