<?php

namespace App\VideoLab\Services;

use App\VideoLab\Exceptions\VideoRejectedException;
use App\VideoLab\Models\Video;
use App\VideoLab\Support\MagicBytes;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcode HLS cho 1 video (chạy trong job queue `video`, worker sandbox). Idempotent theo guid: xoá output dở
 * trước khi chạy; ghi vào thư mục tạm rồi đổi tên nguyên khối để không bao giờ phục vụ nửa chừng.
 */
class TranscodeService
{
    public function __construct(
        private readonly VideoLabStorage $storage,
        private readonly MediaToolkit $media,
    ) {}

    /**
     * File bị từ chối (VideoRejectedException) → status 5 + webhook, KHÔNG ném (không thử lại).
     * Lỗi hạ tầng (ffmpeg crash/timeout) được ném lại để queue thử lại; hết lượt → markFailed() từ job.failed().
     *
     * @throws Throwable
     */
    public function run(string $guid): void
    {
        $video = Video::query()->where('guid', $guid)->first();

        if ($video === null || ! in_array($video->status, [Video::UPLOADED, Video::PROCESSING, Video::TRANSCODING], true)) {
            return; // đã xong/lỗi/bị xoá
        }

        $source = $this->storage->source($guid);

        try {
            $video->forceFill(['status' => Video::PROCESSING, 'error' => null])->save();

            $format = is_file($source) ? MagicBytes::detect($source) : null;

            if ($format === null) {
                throw new VideoRejectedException('Định dạng tệp không được hỗ trợ.');
            }

            $info = $this->media->probe($source, $format);

            $video->forceFill(['status' => Video::TRANSCODING])->save();
            $renditions = $this->encode($guid, $source, $format, $info);

            if ($renditions === null) {
                return; // video bị xoá giữa chừng: đã dọn output, không webhook
            }

            $video->forceFill([
                'status' => Video::FINISHED,
                'length_seconds' => max(1, (int) round($info['duration'])),
                'renditions' => $renditions,
                'error' => null,
                'finished_at' => now(),
                'notified_at' => null,
            ])->save();

            // File gốc được giữ `source_retention_days` ngày rồi `videolab:cleanup` xoá (trừ khi keep_source).
            // Cụm 4 M1: KHÔNG dispatch webhook ở đây (worker không được ghi vào queue `default` của app). Scheduler của
            // app chạy `videolab:notify` để báo video xong/lỗi.
        } catch (VideoRejectedException $e) {
            $this->markFailed($guid, $e->getMessage(), Video::ERROR);
        }
    }

    /** Đánh dấu lỗi cuối cùng (không ghi đè video đã xong) và báo webhook (qua `videolab:notify`). */
    public function markFailed(string $guid, string $message, int $status = Video::ERROR): void
    {
        $video = Video::query()->where('guid', $guid)->first();

        if ($video === null || $video->status === Video::FINISHED) {
            return;
        }

        File::deleteDirectory($this->storage->hlsWorkDir($guid));
        File::deleteDirectory($this->storage->hlsDir($guid));

        $video->forceFill(['status' => $status, 'error' => mb_substr($message, 0, 500), 'notified_at' => null])->save();
    }

    /**
     * @param  array{duration: float, width: int, height: int, has_audio: bool}  $info
     * @return list<array{height: int, width: int, path: string, bandwidth: int}>|null null nếu video đã bị xoá
     */
    private function encode(string $guid, string $source, string $format, array $info): ?array
    {
        $work = $this->storage->hlsWorkDir($guid);
        $final = $this->storage->hlsDir($guid);

        File::deleteDirectory($work);
        File::deleteDirectory($final);
        File::ensureDirectoryExists($work, 0775);

        $ladder = (array) config('videolab.ffmpeg.renditions');
        $heights = array_keys($ladder);
        sort($heights);
        $chosen = array_values(array_filter($heights, static fn (int $h) => $h <= $info['height']));
        $bitrates = $ladder;

        if ($chosen === []) {
            // Cụm 2 L2: nguồn nhỏ hơn mọi bậc thì giữ NGUYÊN chiều cao nguồn (làm chẵn), không phóng to; dùng bitrate
            // của bậc thấp nhất. `validateProbe` đã đảm bảo chiều cao nguồn >= min_dimension nên đường dẫn `{h}p` đủ 3 chữ số.
            $native = $info['height'] - ($info['height'] % 2);
            $chosen = [$native];
            $bitrates = [$native => $ladder[$heights[0]]];
        }

        $out = [];

        try {
            foreach ($chosen as $height) {
                [$videoKbps, $audioKbps] = $bitrates[$height];
                $dir = $work.'/'.$height.'p';
                File::ensureDirectoryExists($dir, 0775);

                $this->media->encodeRendition($source, $format, $dir, $height, (int) $videoKbps, (int) $audioKbps, $info['has_audio']);

                $out[] = [
                    'height' => $height,
                    'width' => $this->evenWidth($info['width'], $info['height'], $height),
                    'path' => $height.'p/index.m3u8',
                    'bandwidth' => ((int) $videoKbps + (int) $audioKbps) * 1000,
                ];
            }

            file_put_contents($work.'/playlist.m3u8', $this->masterPlaylist($out));

            if (! rename($work, $final)) {
                throw new \RuntimeException('Không đổi tên được thư mục HLS.');
            }

            // Kiểm lại SAU rename (xoá video có thể xảy ra bất kỳ lúc nào): nếu bản ghi đã mất thì dọn sạch output.
            if (! Video::query()->where('guid', $guid)->exists()) {
                $this->storage->deleteAll($guid);

                return null;
            }
        } catch (Throwable $e) {
            File::deleteDirectory($work);
            Log::warning('VideoLab transcode lỗi', ['guid' => $guid, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            throw $e;
        }

        return $out;
    }

    /**
     * @param  list<array{height: int, width: int, path: string, bandwidth: int}>  $renditions
     */
    private function masterPlaylist(array $renditions): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($renditions as $r) {
            $lines[] = '#EXT-X-STREAM-INF:BANDWIDTH='.$r['bandwidth'].',RESOLUTION='.$r['width'].'x'.$r['height'];
            $lines[] = $r['path'];
        }

        return implode("\n", $lines)."\n";
    }

    private function evenWidth(int $w, int $h, int $targetHeight): int
    {
        $width = (int) round($w * $targetHeight / max(1, $h));

        return $width - ($width % 2);
    }
}
