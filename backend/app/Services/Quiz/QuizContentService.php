<?php

namespace App\Services\Quiz;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Câu hỏi/lựa chọn của quiz. Quy tắc "đúng 4 lựa chọn, đúng 1 đáp án đúng" và trần số câu kiểm ở đây (không ép ở DB).
 *
 * Copy-on-write (data-model §3.4): câu đã có lượt làm (`quiz_attempts.question_ids` chứa id câu) không bao giờ bị
 * UPDATE tại chỗ — soft delete câu cũ (`replaced_by_id` → câu mới, cùng `position`) và tạo câu mới kèm lựa chọn mới;
 * lượt làm cũ đọc câu bằng `QuizQuestion::withTrashed()` (lựa chọn của câu cũ được giữ nguyên, không soft delete).
 * Id câu hỏi vì vậy có thể ĐỔI sau khi sửa: client dùng id trong response.
 *
 * Khoá: dòng quiz (`lockForUpdate`) cho mọi ghi câu hỏi. T22 khi tạo lượt làm phải đọc quiz với
 * `lockForUpdate`/`sharedLock` trước khi chốt `question_ids` để không lọt giữa "kiểm có lượt" và "sửa tại chỗ".
 */
class QuizContentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, QuizQuestion> câu đang dùng kèm lựa chọn (có `is_correct`: chỉ dùng cho quản trị) */
    public function list(Quiz $quiz): Collection
    {
        return $quiz->questions()->with('options')->get();
    }

    /**
     * @param  array{content: string, explanation?: string|null, options: list<array{content: string, is_correct: bool}>}  $data
     */
    public function create(Course $course, Quiz $quiz, array $data): QuizQuestion
    {
        return DB::transaction(function () use ($course, $quiz, $data): QuizQuestion {
            $locked = $this->lockQuiz($course, $quiz);
            $this->assertOptions($data['options']);

            $max = (int) config('quiz.max_questions');

            if (QuizQuestion::query()->where('quiz_id', $locked->getKey())->count() >= $max) {
                throw new DomainException('QUIZ_QUESTION_LIMIT', "Mỗi quiz tối đa {$max} câu hỏi.", 422);
            }

            $position = ((int) QuizQuestion::query()->where('quiz_id', $locked->getKey())->max('position')) + 1;
            $question = $this->insertQuestion($locked, $data, $position);

            $this->audit->log('quiz_question.create', $question, ['course_id' => $course->getKey(), 'quiz_id' => $locked->getKey()]);

            return $question->load('options');
        });
    }

    /**
     * @param  array{content: string, explanation?: string|null, options: list<array{content: string, is_correct: bool}>}  $data
     */
    public function update(Course $course, Quiz $quiz, QuizQuestion $question, array $data): QuizQuestion
    {
        return DB::transaction(function () use ($course, $quiz, $question, $data): QuizQuestion {
            $locked = $this->lockQuiz($course, $quiz);
            $this->assertOptions($data['options']);

            $current = QuizQuestion::query()->where('quiz_id', $locked->getKey())->whereKey($question->getKey())->firstOrFail();

            if ($this->hasAttempts($locked, $current)) {
                $new = $this->insertQuestion($locked, $data, (int) $current->position);
                $current->forceFill(['replaced_by_id' => $new->getKey()])->save();
                $current->delete();

                $this->audit->log('quiz_question.update', $new, [
                    'course_id' => $course->getKey(),
                    'quiz_id' => $locked->getKey(),
                    'copy_on_write' => true,
                    'replaces' => $current->getKey(),
                ]);

                return $new->load('options');
            }

            $current->content = $data['content'];
            $current->explanation = $data['explanation'] ?? null;
            $current->save();

            $existing = $current->options()->get()->keyBy('position');

            foreach ($data['options'] as $i => $option) {
                $row = $existing->get($i + 1) ?? new QuizOption(['position' => $i + 1]);
                $row->fill(['content' => $option['content'], 'is_correct' => $option['is_correct']]);
                $row->question_id = $current->getKey();
                $row->save();
            }

            $this->audit->log('quiz_question.update', $current, [
                'course_id' => $course->getKey(),
                'quiz_id' => $locked->getKey(),
                'copy_on_write' => false,
            ]);

            return $current->load('options');
        });
    }

    /** Xoá mềm câu hỏi. Idempotent khi đã bị xoá đồng thời. Câu đã có lượt làm: giữ nguyên lựa chọn cho kết quả cũ. */
    public function delete(Course $course, Quiz $quiz, QuizQuestion $question): void
    {
        DB::transaction(function () use ($course, $quiz, $question): void {
            $locked = $this->lockQuiz($course, $quiz);
            $current = QuizQuestion::query()->where('quiz_id', $locked->getKey())->whereKey($question->getKey())->first();

            if ($current === null) {
                return;
            }

            $kept = $this->hasAttempts($locked, $current);

            if (! $kept) {
                $current->options()->delete();
            }

            $current->delete();

            $this->audit->log('quiz_question.delete', $current, [
                'course_id' => $course->getKey(),
                'quiz_id' => $locked->getKey(),
                'kept_for_attempts' => $kept,
            ]);
        });
    }

    /**
     * Câu đã được ít nhất 1 lượt làm tham chiếu? (cả lượt đang làm lẫn đã nộp). Bảng `quiz_attempts` do T22 tạo;
     * trước đó chưa thể có lượt nào.
     */
    public function hasAttempts(Quiz $quiz, QuizQuestion $question): bool
    {
        try {
            return DB::table('quiz_attempts')
                ->where('quiz_id', $quiz->getKey())
                ->whereRaw('JSON_CONTAINS(question_ids, CAST(? AS JSON))', [(string) (int) $question->getKey()])
                ->exists();
        } catch (QueryException $e) {
            if ($e->getCode() === '42S02') { // bảng chưa tồn tại (trước T22) → chưa thể có lượt làm
                return false;
            }

            throw $e;
        }
    }

    /**
     * @param  array{content: string, explanation?: string|null, options: list<array{content: string, is_correct: bool}>}  $data
     */
    private function insertQuestion(Quiz $quiz, array $data, int $position): QuizQuestion
    {
        $question = new QuizQuestion(['content' => $data['content'], 'explanation' => $data['explanation'] ?? null]);
        $question->forceFill(['quiz_id' => $quiz->getKey(), 'position' => $position])->save();

        foreach ($data['options'] as $i => $option) {
            $row = new QuizOption(['content' => $option['content'], 'is_correct' => $option['is_correct'], 'position' => $i + 1]);
            $row->question_id = $question->getKey();
            $row->save();
        }

        return $question;
    }

    /**
     * @param  array<int, array{content: string, is_correct: bool}>  $options
     */
    private function assertOptions(array $options): void
    {
        $required = (int) config('quiz.options_per_question');

        if (count($options) !== $required || count(array_filter($options, fn (array $o): bool => $o['is_correct'] === true)) !== 1) {
            throw new DomainException('QUIZ_OPTIONS_INVALID', "Mỗi câu hỏi phải có đúng {$required} lựa chọn và đúng 1 đáp án đúng.", 422);
        }
    }

    private function lockQuiz(Course $course, Quiz $quiz): Quiz
    {
        return Quiz::query()->where('course_id', $course->getKey())->whereKey($quiz->getKey())->lockForUpdate()->firstOrFail();
    }
}
