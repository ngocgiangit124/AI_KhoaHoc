<?php

namespace App\Models;

use Database\Factories\QuizOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lựa chọn A–D. `is_correct` nằm trong $hidden: serialize mặc định không lộ đáp án đúng (I3); chỉ resource quản
 * trị và resource kết quả sau khi nộp mới đọc tường minh.
 */
class QuizOption extends Model
{
    /** @use HasFactory<QuizOptionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['content', 'is_correct', 'position'];

    /**
     * @var list<string>
     */
    protected $hidden = ['is_correct'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<QuizQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }
}
