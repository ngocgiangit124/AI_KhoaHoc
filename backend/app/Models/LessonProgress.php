<?php

namespace App\Models;

use App\Enums\LessonProgressStatus;
use Database\Factories\LessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tiến độ bài học (bảng ghi nhiều nhất). Ghi bằng upsert của ProgressService (T21), không qua mass assignment.
 *
 * @property LessonProgressStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon $last_accessed_at
 * @property Carbon|null $last_heartbeat_at
 */
class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory;

    protected $table = 'lesson_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
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
