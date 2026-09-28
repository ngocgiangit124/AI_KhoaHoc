<?php

namespace App\Models;

use App\Enums\VideoAssetStatus;
use Database\Factories\VideoAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tầng nghiệp vụ video, độc lập nhà cung cấp — ADR-002, data-model §3.2.
 * Không có route/API công khai nào lộ trực tiếp bảng này (S13) — chỉ đọc qua
 * `LessonAccessService`/`PlaybackController` (T11–T13).
 *
 * @property VideoAssetStatus $status
 */
class VideoAsset extends Model
{
    /** @use HasFactory<VideoAssetFactory> */
    use HasFactory;

    /**
     * S17 — `status`, `duration_seconds`, `error_message` chỉ đổi qua job/webhook
     * xử lý video (T11/T12), không fillable trực tiếp từ request người dùng.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_library_id',
        'provider_video_id',
        'original_filename',
        'size_bytes',
        'lesson_id',
        'declared_size_bytes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoAssetStatus::class,
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'declared_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
