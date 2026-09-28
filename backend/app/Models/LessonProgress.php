<?php

namespace App\Models;

use App\Enums\LessonProgressStatus;
use Database\Factories\LessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bảng lớn nhất + ghi nhiều nhất (data-model §2, §3.3, DBA checklist §2.5).
 * `course_id` denormalize, CỐ Ý KHÔNG có FK (giảm chi phí ghi).
 *
 * @property LessonProgressStatus $status
 */
class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory;

    /**
     * S17 — `watched_seconds`, `last_position_seconds`, `status`,
     * `completed_at`, `last_heartbeat_at` chỉ đổi qua `ProgressService`
     * (T13, heartbeat với `lockForUpdate()` — DBA §2.5), không fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonProgressStatus::class,
            'watched_seconds' => 'integer',
            'last_position_seconds' => 'integer',
            'completed_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
