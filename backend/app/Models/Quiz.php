<?php

namespace App\Models;

use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * data-model §3.4 (US-007, US-009). Gắn với ĐÚNG 1 bài HOẶC 1 chương
 * (`chk_quizzes_single_parent`). `course_id` luôn suy ra từ chương/bài, không
 * bao giờ từ request (S5).
 *
 * @property int $id
 * @property int $course_id
 * @property int|null $chapter_id
 * @property int|null $lesson_id
 * @property string $title
 * @property int|null $time_limit_minutes
 * @property int $position
 */
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'chapter_id',
        'lesson_id',
        'title',
        'time_limit_minutes',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'position' => 'integer',
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
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Câu hỏi CÒN SỐNG (chưa xoá mềm) theo thứ tự hiển thị. Tên `questions`
     * (số nhiều của tham số route `{question}`) để `scopeBindings()` tự suy ra
     * quan hệ cha–con.
     *
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
