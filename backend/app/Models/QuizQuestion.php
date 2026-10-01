<?php

namespace App\Models;

use Database\Factories\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * data-model §3.4. `content`/`explanation` là văn bản thuần (có `$...$`
 * LaTeX), không HTML. `replaced_by_id` do `QuizQuestionService` gán khi
 * copy-on-write (không fillable — S17).
 *
 * @property int $id
 * @property int $quiz_id
 * @property string $content
 * @property string|null $explanation
 * @property int $position
 * @property int|null $replaced_by_id
 */
class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quiz_id',
        'content',
        'explanation',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'replaced_by_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Lựa chọn CÒN SỐNG theo thứ tự A–D.
     *
     * @return HasMany<QuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class, 'question_id')->orderBy('position')->orderBy('id');
    }
}
