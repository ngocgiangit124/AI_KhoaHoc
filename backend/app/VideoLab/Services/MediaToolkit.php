<?php

namespace App\VideoLab\Services;

use App\VideoLab\Exceptions\VideoRejectedException;
use App\VideoLab\Support\MagicBytes;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Bọc ffprobe/ffmpeg (ADR-002 §3a.2): luôn `Process` với MẢNG tham số (không shell), khoá cứng demuxer và protocol
 * (`-protocol_whitelist file`, `-f <định dạng đã xác định bằng magic bytes>`, `-format_whitelist`). Đường dẫn đầu vào
 * do server sinh (không từ client). Tách thành class để test thay bằng bản giả (không cần ffmpeg).
 */
class MediaToolkit
{
    public const FORMAT_WHITELIST = 'mov,mp4,m4a,3gp,3g2,mj2,matroska,webm';

    /**
     * @param  'mov'|'matroska'  $format  kết quả MagicBytes::detect
     * @return array{duration: float, width: int, height: int, has_audio: bool}
     *
     * @throws VideoRejectedException file không hợp lệ/vượt giới hạn
     */
    public function probe(string $source, string $format): array
    {
        $process = new Process($this->probeCommand($source, $format), null, $this->cleanEnv(), null, (float) config('videolab.ffmpeg.probe_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new VideoRejectedException('Không đọc được thông tin video (quá thời gian).');
        }

        if (! $process->isSuccessful()) {
            throw new VideoRejectedException('Tệp video không hợp lệ hoặc bị hỏng.');
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data)) {
            throw new VideoRejectedException('Tệp video không hợp lệ hoặc bị hỏng.');
        }

        return $this->validateProbe($data);
    }

    /**
     * Mã hoá 1 độ phân giải ra HLS (`{outDir}/index.m3u8` + `seg_%05d.ts`).
     *
     * @param  'mov'|'matroska'  $format
     */
    public function encodeRendition(string $source, string $format, string $outDir, int $height, int $videoKbps, int $audioKbps, bool $hasAudio): void
    {
        $process = new Process(
            $this->encodeCommand($source, $format, $outDir, $height, $videoKbps, $audioKbps, $hasAudio),
            null,
            $this->cleanEnv(),
            null,
            (float) config('videolab.ffmpeg.encode_timeout'),
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('ffmpeg quá thời gian.');
        }

        if (! $process->isSuccessful()) {
            // Không đưa stderr (có thể chứa đường dẫn) vào message lưu DB; chỉ log ở tầng gọi.
            throw new RuntimeException('ffmpeg thất bại (mã '.$process->getExitCode().'): '.mb_substr($process->getErrorOutput(), -500));
        }
    }

    /**
     * Env sạch cho ffmpeg/ffprobe: mọi biến của worker (DB_PASSWORD, REDIS_PASSWORD...) bị gỡ (false = unset),
     * chỉ giữ PATH và HOME=/tmp. Tiến trình bị khai thác cũng không đọc được bí mật từ môi trường.
     *
     * @return array<string, string|false>
     */
    public function cleanEnv(): array
    {
        $env = [];

        foreach (array_keys(getenv()) as $name) {
            $env[(string) $name] = false;
        }

        foreach (array_keys($_SERVER) as $name) {
            if (is_string($name) && is_string($_SERVER[$name])) {
                $env[$name] = false;
            }
        }

        return ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => '/tmp'] + $env;
    }

    /**
     * @return list<string>
     */
    public function probeCommand(string $source, string $format): array
    {
        return [
            (string) config('videolab.ffmpeg.ffprobe'),
            '-v', 'error',
            '-protocol_whitelist', 'file',
            '-format_whitelist', self::FORMAT_WHITELIST,
            '-f', $this->demuxer($format),
            '-show_format', '-show_streams', '-of', 'json',
            $source,
        ];
    }

    /**
     * @return list<string>
     */
    public function encodeCommand(string $source, string $format, string $outDir, int $height, int $videoKbps, int $audioKbps, bool $hasAudio): array
    {
        $segment = (int) config('videolab.ffmpeg.segment_seconds');

        $cmd = [
            (string) config('videolab.ffmpeg.ffmpeg'),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-threads', (string) config('videolab.ffmpeg.threads'),
            '-protocol_whitelist', 'file',
            '-format_whitelist', self::FORMAT_WHITELIST,
            '-f', $this->demuxer($format),
            '-i', $source,
            '-sn', '-dn',
            '-map', '0:v:0',
        ];

        if ($hasAudio) {
            array_push($cmd, '-map', '0:a:0');
        }

        array_push(
            $cmd,
            '-vf', 'scale=-2:'.$height.',format=yuv420p',
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'main', '-crf', '23',
            '-maxrate', $videoKbps.'k', '-bufsize', ($videoKbps * 2).'k',
            '-force_key_frames', 'expr:gte(t,n_forced*'.$segment.')',
            '-sc_threshold', '0',
        );

        if ($hasAudio) {
            array_push($cmd, '-c:a', 'aac', '-b:a', $audioKbps.'k', '-ac', '2');
        }

        array_push(
            $cmd,
            '-f', 'hls',
            '-hls_time', (string) $segment,
            '-hls_playlist_type', 'vod',
            '-hls_flags', 'independent_segments',
            '-hls_segment_filename', rtrim($outDir, '/').'/seg_%05d.ts',
            rtrim($outDir, '/').'/index.m3u8',
        );

        return $cmd;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{duration: float, width: int, height: int, has_audio: bool}
     */
    private function validateProbe(array $data): array
    {
        $formatName = (string) ($data['format']['format_name'] ?? '');
        $allowed = explode(',', self::FORMAT_WHITELIST);

        if (array_intersect(explode(',', $formatName), $allowed) === []) {
            throw new VideoRejectedException('Định dạng tệp không được hỗ trợ.');
        }

        $video = null;

        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? null) === 'video'
                && (int) ($stream['disposition']['attached_pic'] ?? 0) === 0
                && (int) ($stream['width'] ?? 0) > 0 && (int) ($stream['height'] ?? 0) > 0) {
                $video = $stream;
                break;
            }
        }

        if ($video === null) {
            throw new VideoRejectedException('Tệp không có luồng video.');
        }

        $duration = (float) ($data['format']['duration'] ?? $video['duration'] ?? 0);

        if ($duration <= 0) {
            throw new VideoRejectedException('Không xác định được thời lượng video.');
        }

        if ($duration > (int) config('videolab.ffmpeg.max_duration_minutes') * 60) {
            throw new VideoRejectedException('Video dài quá '.config('videolab.ffmpeg.max_duration_minutes').' phút.');
        }

        $width = (int) $video['width'];
        $height = (int) $video['height'];

        if ($width > (int) config('videolab.ffmpeg.max_width') || $height > (int) config('videolab.ffmpeg.max_height')) {
            throw new VideoRejectedException('Độ phân giải vượt quá '.config('videolab.ffmpeg.max_width').'x'.config('videolab.ffmpeg.max_height').'.');
        }

        // Cụm 2 L2: tỉ lệ khung hình bất thường (dải 3840x100...) và nguồn quá nhỏ bị từ chối (không phóng to nguồn nhỏ).
        $min = (int) config('videolab.ffmpeg.min_dimension');
        $maxRatio = (float) config('videolab.ffmpeg.max_aspect_ratio');

        if ($width < $min || $height < $min) {
            throw new VideoRejectedException('Độ phân giải quá nhỏ (tối thiểu '.$min.'x'.$min.').');
        }

        if ($maxRatio > 0 && (max($width, $height) / min($width, $height)) > $maxRatio) {
            throw new VideoRejectedException('Tỉ lệ khung hình không được hỗ trợ.');
        }

        return ['duration' => $duration, 'width' => $width, 'height' => $height, 'has_audio' => $this->hasAudio($data)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasAudio(array $data): bool
    {
        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? null) === 'audio') {
                return true;
            }
        }

        return false;
    }

    private function demuxer(string $format): string
    {
        return $format === MagicBytes::MATROSKA ? 'matroska' : 'mov';
    }
}
