<?php

namespace App\Models;

use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * data-model §3.4. Bảng tạo ở T21 (phục vụ copy-on-write); logic làm bài,
 * autosave, chấm điểm thuộc T22. Mọi cột do server quyết định nên KHÔNG có
 * cột nào fillable (S17) — T22 sẽ ghi qua Service chuyên trách.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property int $course_id
 * @property list<int> $question_ids
 */
class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'question_ids' => 'array',
            'answers' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'auto_submitted' => 'boolean',
            'total_questions' => 'integer',
            'correct_count' => 'integer',
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
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
