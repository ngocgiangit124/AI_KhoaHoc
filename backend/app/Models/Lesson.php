<?php

namespace App\Models;

use App\Enums\VideoSource;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * data-model §3.2 (US-003, US-009 BR10). `external_video_id` KHÔNG lưu URL
 * người nhập — chỉ ID bắt bằng regex chặt (S13); Resource công khai (T10)
 * KHÔNG BAO GIỜ trả `video_asset_id`/`external_video_id` thô trực tiếp.
 *
 * @property VideoSource $video_source
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * S17 — `video_source`, `video_asset_id`, `external_provider`,
     * `external_video_id`, `duration_seconds` chỉ đổi qua Service chuyên
     * trách (LessonService/LessonVideoUploadController — T09/T11), không
     * fillable trực tiếp (đầu vào luôn qua Form Request + Service, không
     * `Lesson::create($request->all())`).
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'chapter_id',
        'title',
        'position',
        'is_preview',
    ];

    protected function casts(): array
    {
        return [
            'video_source' => VideoSource::class,
            'position' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
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

    /**
     * @return HasMany<Quiz, $this>
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }
}
