<?php

namespace App\VideoLab\Support;

/**
 * Nhận diện định dạng bằng magic bytes TRƯỚC mọi lệnh ffprobe/ffmpeg (ADR-002 §3a.1). Tên file/Content-Type do
 * client khai không được tin. Chỉ nhận MP4/MOV (`ftyp` ở byte 4–7) và Matroska/WebM (`1A 45 DF A3`).
 */
class MagicBytes
{
    public const MOV = 'mov';

    public const MATROSKA = 'matroska';

    /** @return 'mov'|'matroska'|null */
    public static function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 12);
        fclose($handle);

        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
            return self::MOV;
        }

        if (strlen($head) >= 4 && substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
            return self::MATROSKA;
        }

        return null;
    }
}
