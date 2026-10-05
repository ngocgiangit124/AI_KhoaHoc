<?php

namespace App\Models;

use App\Enums\VideoSource;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bài học. `course_id`, `chapter_id`, `video_asset_id` KHÔNG nằm trong $fillable (S5): chỉ gán qua quan hệ /
 * LessonVideoUploadController của chính bài đó (T09+).
 *
 * @property VideoSource $video_source
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'position',
        'video_source',
        'external_provider',
        'external_video_id',
        'duration_seconds',
        'is_preview',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'video_source' => VideoSource::class,
            'is_preview' => 'boolean',
            'position' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * @return BelongsTo<VideoAsset, $this>
     */
    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class);
    }
}
