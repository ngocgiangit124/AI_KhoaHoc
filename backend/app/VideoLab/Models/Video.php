<?php

namespace App\VideoLab\Models;

use Database\Factories\VideoLab\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Video của VideoLab (mô phỏng Bunny). Mọi cột do server đặt, không mass-assign từ request.
 *
 * @property string $guid
 * @property int $status
 * @property int|null $upload_length
 * @property int $upload_offset
 * @property int $max_bytes
 * @property Carbon $upload_expires_at
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    public const CREATED = 0;

    public const UPLOADED = 1;

    public const PROCESSING = 2;

    public const TRANSCODING = 3;

    public const FINISHED = 4;

    public const ERROR = 5;

    public const UPLOAD_FAILED = 6;

    public const GUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    protected $table = 'vl_videos';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'length_seconds' => 'integer',
            'max_bytes' => 'integer',
            'upload_length' => 'integer',
            'upload_offset' => 'integer',
            'upload_expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'renditions' => 'array',
        ];
    }

    protected static function newFactory(): VideoFactory
    {
        return VideoFactory::new();
    }

    public static function isGuid(string $value): bool
    {
        return preg_match('/^'.self::GUID_PATTERN.'$/', $value) === 1;
    }
}
