<?php

namespace App\Services\Quiz;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Câu hỏi/lựa chọn của quiz. Quy tắc "đúng 4 lựa chọn, đúng 1 đáp án đúng" và trần số câu kiểm ở đây (không ép ở DB).
 *
 * Copy-on-write (data-model §3.4): câu đã có lượt làm (`quiz_attempts.question_ids` chứa id câu) không bao giờ bị
 * UPDATE tại chỗ — soft delete câu cũ (`replaced_by_id` → câu mới, cùng `position`) và tạo câu mới kèm lựa chọn mới;
 * lượt làm cũ đọc câu bằng `QuizQuestion::withTrashed()` (lựa chọn của câu cũ được giữ nguyên, không soft delete).
 * Id câu hỏi vì vậy có thể ĐỔI sau khi sửa: client dùng id trong response.
 *
 * Khoá: dòng quiz (`lockForUpdate`) cho mọi ghi câu hỏi. Tạo lượt làm (QuizAttemptService::start) đọc quiz với
 * `sharedLock` trước khi chốt `question_ids` nên không lọt giữa "kiểm có lượt" và "sửa tại chỗ".
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
            $this->renumber($locked);

            $this->audit->log('quiz_question.delete', $current, [
                'course_id' => $course->getKey(),
                'quiz_id' => $locked->getKey(),
                'kept_for_attempts' => $kept,
            ]);
        });
    }

    /**
     * Đổi thứ tự câu (position 1..n). Chỉ đổi `position`, giữ id (KHÔNG copy-on-write): lượt làm đã chốt
     * `question_ids` lúc bắt đầu nên không bị ảnh hưởng. `$ids` phải đúng toàn bộ câu chưa xoá của quiz.
     *
     * @param  list<int>  $ids
     * @return Collection<int, QuizQuestion>
     */
    public function reorder(Course $course, Quiz $quiz, array $ids): Collection
    {
        return DB::transaction(function () use ($course, $quiz, $ids): Collection {
            $locked = $this->lockQuiz($course, $quiz);
            $positions = QuizQuestion::query()->where('quiz_id', $locked->getKey())->pluck('position', 'id');

            $existing = $positions->keys()->map(fn ($id): int => (int) $id)->all();
            $sent = array_map('intval', $ids);
            sort($existing);
            $sortedSent = $sent;
            sort($sortedSent);

            if ($existing !== $sortedSent || count(array_unique($sent)) !== count($sent)) {
                throw new DomainException('QUIZ_QUESTIONS_MISMATCH', 'Danh sách câu hỏi không khớp với dữ liệu hiện tại của quiz. Vui lòng tải lại trang.', 422);
            }

            $moved = 0;

            foreach ($sent as $i => $id) {
                if ((int) $positions[$id] !== $i + 1) {
                    QuizQuestion::query()->whereKey($id)->update(['position' => $i + 1]);
                    $moved++;
                }
            }

            $this->audit->log('quiz_question.reorder', $locked, [
                'course_id' => $course->getKey(),
                'quiz_id' => $locked->getKey(),
                'questions' => count($sent),
                'rows_changed' => $moved,
            ]);

            return $this->list($locked);
        });
    }

    /** Đánh lại position liền mạch 1..n cho câu chưa xoá (giữ thứ tự position, id). Gọi dưới khoá quiz. */
    private function renumber(Quiz $quiz): void
    {
        $rows = QuizQuestion::query()->where('quiz_id', $quiz->getKey())->orderBy('position')->orderBy('id')->pluck('position', 'id');
        $i = 0;

        foreach ($rows as $id => $position) {
            $i++;

            if ((int) $position !== $i) {
                QuizQuestion::query()->whereKey($id)->update(['position' => $i]);
            }
        }
    }

    /**
     * Câu đã được ít nhất 1 lượt làm tham chiếu? (cả lượt đang làm lẫn đã nộp). `question_ids` là JSON mảng số
     * nguyên nên JSON_CONTAINS với số nguyên khớp; lọc theo `quiz_id` (index) trước. Gọi dưới `lockForUpdate` dòng
     * quiz; QuizAttemptService::start đọc quiz bằng `sharedLock` trước khi chốt `question_ids`.
     */
    public function hasAttempts(Quiz $quiz, QuizQuestion $question): bool
    {
        return DB::table('quiz_attempts')
            ->where('quiz_id', $quiz->getKey())
            ->whereRaw('JSON_CONTAINS(question_ids, CAST(? AS JSON))', [(string) (int) $question->getKey()])
            ->exists();
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
