<?php

namespace App\Services\Quiz;

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quiz của khóa học (US-007 BR1, US-009). `course_id` suy ra từ route (khóa đã qua scopeBindings + policy), không
 * từ request. Mọi ghi KHOÁ dòng khóa học trước (cùng thứ tự với CurriculumService, tránh lệch với xoá chương/bài
 * đồng thời), rồi dòng quiz. Thứ tự khoá cố định: courses → quizzes.
 */
class QuizService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, Quiz> quiz chưa xoá của khóa, kèm số câu hỏi đang dùng và nơi gắn */
    public function list(Course $course): Collection
    {
        return $course->quizzes()
            ->with(['chapter:id,title', 'lesson:id,title'])
            ->withCount('questions')
            ->orderBy('position')->orderBy('id')
            ->get();
    }

    /**
     * @param  array{title: string, chapter_id?: int|null, lesson_id?: int|null, time_limit_minutes?: int|null}  $data
     */
    public function create(Course $course, array $data): Quiz
    {
        return DB::transaction(function () use ($course, $data): Quiz {
            $this->lockCourse($course);
            [$chapterId, $lessonId] = $this->resolveParent($course, $data);

            $quiz = new Quiz(['title' => $data['title']]);
            $quiz->time_limit_minutes = $this->timeLimit($data['time_limit_minutes'] ?? null);
            $quiz->forceFill([
                'course_id' => $course->getKey(),
                'chapter_id' => $chapterId,
                'lesson_id' => $lessonId,
                'position' => ((int) Quiz::query()->withTrashed()->where('course_id', $course->getKey())->max('position')) + 1,
            ])->save();

            $this->audit->log('quiz.create', $quiz, [
                'course_id' => $course->getKey(),
                'chapter_id' => $chapterId,
                'lesson_id' => $lessonId,
                'title' => $quiz->title,
                'time_limit_minutes' => $quiz->time_limit_minutes,
            ]);

            return $quiz;
        });
    }

    /**
     * @param  array{title: string, chapter_id?: int|null, lesson_id?: int|null, time_limit_minutes?: int|null}  $data
     */
    public function update(Course $course, Quiz $quiz, array $data): Quiz
    {
        return DB::transaction(function () use ($course, $quiz, $data): Quiz {
            $this->lockCourse($course);
            $locked = $this->lockQuiz($course, $quiz);
            [$chapterId, $lessonId] = $this->resolveParent($course, $data);

            $locked->title = $data['title'];

            // Cờ tắt: bỏ qua giá trị gửi lên, giữ nguyên giá trị đang có.
            if (config('features.quiz_time_limit')) {
                $locked->time_limit_minutes = $this->timeLimit($data['time_limit_minutes'] ?? null);
            }

            $locked->forceFill(['chapter_id' => $chapterId, 'lesson_id' => $lessonId]);

            $changes = [];

            foreach ($locked->getDirty() as $field => $to) {
                $changes[$field] = ['from' => $locked->getOriginal($field), 'to' => $to];
            }

            if ($changes !== []) {
                $locked->save();
                $this->audit->log('quiz.update', $locked, ['course_id' => $course->getKey(), 'fields' => array_keys($changes)] + $changes);
            }

            return $locked;
        });
    }

    /** Xoá mềm quiz (câu hỏi giữ nguyên để lượt làm cũ còn đọc được). Idempotent khi đã bị xoá đồng thời. */
    public function delete(Course $course, Quiz $quiz): void
    {
        DB::transaction(function () use ($course, $quiz): void {
            $this->lockCourse($course);
            $locked = Quiz::query()->where('course_id', $course->getKey())->whereKey($quiz->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $locked->delete();

            $this->audit->log('quiz.delete', $locked, ['course_id' => $course->getKey(), 'title' => $locked->title]);
        });
    }

    private function lockCourse(Course $course): void
    {
        Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockQuiz(Course $course, Quiz $quiz): Quiz
    {
        return Quiz::query()->where('course_id', $course->getKey())->whereKey($quiz->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Chương/bài phải còn tồn tại và thuộc đúng khóa (kiểm lại dưới khoá; request đã kiểm trên bản đọc cũ).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveParent(Course $course, array $data): array
    {
        $chapterId = isset($data['chapter_id']) ? (int) $data['chapter_id'] : null;
        $lessonId = isset($data['lesson_id']) ? (int) $data['lesson_id'] : null;

        if (($chapterId === null) === ($lessonId === null)) {
            throw new DomainException('QUIZ_PARENT_INVALID', 'Quiz phải gắn với đúng một chương hoặc một bài học.', 422);
        }

        $exists = $chapterId !== null
            ? Chapter::query()->where('course_id', $course->getKey())->whereKey($chapterId)->exists()
            : Lesson::query()->where('course_id', $course->getKey())->whereKey($lessonId)->exists();

        if (! $exists) {
            throw new DomainException('QUIZ_PARENT_INVALID', 'Chương hoặc bài học không tồn tại trong khóa học này.', 422);
        }

        return [$chapterId, $lessonId];
    }

    private function timeLimit(mixed $value): ?int
    {
        if (! config('features.quiz_time_limit') || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
