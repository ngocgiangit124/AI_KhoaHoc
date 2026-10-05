<?php

namespace App\Models;

use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lượt làm quiz. Chỉ QuizAttemptService ghi (không mass assignment). `result` (đáp án đúng từng câu) và `answers`
 * nằm trong $hidden: không bao giờ lộ qua serialize mặc định; chỉ resource tường minh mới đọc (I3).
 *
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 * @property list<int> $question_ids
 * @property array<int|string, int> $answers
 * @property array<int|string, array{selected: int|null, correct: int|null, ok: bool}>|null $result
 */
class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['result', 'answers', 'question_ids'];

    /**
     * @return array<string, string>
     */
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
            'score' => 'float',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
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
        return $this->belongsTo(Quiz::class)->withTrashed();
    }
}
