<?php

namespace App\Services\Learning;

use App\Enums\LessonProgressStatus;
use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Dữ liệu trang học: outline + trạng thái bài, bài tiếp tục học, bài trước/sau, quiz gắn kèm. Không có
 * URL/ID video; không có đáp án quiz (I3: chỉ đếm câu hỏi).
 */
class LearningOutlineService
{
    public function __construct(private readonly CourseProgressService $courseProgress) {}

    /**
     * @return array<string, mixed>
     */
    public function outline(User $user, Course $course): array
    {
        $userId = (int) $user->getKey();
        $progress = $this->progressMap($userId, (int) $course->getKey());
        $quizzes = $this->quizzes((int) $course->getKey());

        $chapters = Chapter::query()
            ->where('course_id', $course->getKey())
            ->with(['lessons' => fn ($q) => $q->orderBy('position')->orderBy('id')->with('videoAsset:id,status')])
            ->orderBy('position')->orderBy('id')
            ->get();

        $orderedLessonIds = [];
        $data = $chapters->map(function (Chapter $chapter) use ($progress, $quizzes, &$orderedLessonIds): array {
            return [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'position' => $chapter->position,
                'quizzes' => $quizzes['chapter'][$chapter->id] ?? [],
                'lessons' => $chapter->lessons->map(function (Lesson $lesson) use ($progress, $quizzes, &$orderedLessonIds): array {
                    $orderedLessonIds[] = (int) $lesson->id;
                    $row = $progress[$lesson->id] ?? null;

                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'position' => $lesson->position,
                        'is_preview' => $lesson->is_preview,
                        'duration_seconds' => $lesson->duration_seconds,
                        'video_ready' => $this->videoReady($lesson),
                        'status' => $row === null ? 'not_started' : $row['status'],
                        'quizzes' => $quizzes['lesson'][$lesson->id] ?? [],
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return [
            'course' => ['id' => $course->id, 'title' => $course->title, 'slug' => $course->slug],
            'course_percent' => $this->courseProgress->percent($userId, (int) $course->getKey()),
            'resume_lesson_id' => $this->resumeLessonId($orderedLessonIds, $progress),
            'chapters' => $data,
        ];
    }

    /**
     * Chi tiết 1 bài cho trang học.
     *
     * @return array<string, mixed>
     */
    public function lessonDetail(User $user, Lesson $lesson, bool $owned): array
    {
        $lesson->loadMissing(['chapter:id,title', 'course:id,title,slug', 'videoAsset:id,status']);
        $row = LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->first();

        $quizzes = $this->quizzes((int) $lesson->course_id, (int) $lesson->getKey());
        [$prev, $next] = $this->neighbors($lesson, $owned);

        return [
            'lesson' => [
                'id' => $lesson->id,
                'course_id' => $lesson->course_id,
                'chapter_id' => $lesson->chapter_id,
                'chapter_title' => $lesson->chapter?->title,
                'title' => $lesson->title,
                'position' => $lesson->position,
                'is_preview' => $lesson->is_preview,
                'duration_seconds' => $lesson->duration_seconds,
                'video_ready' => $this->videoReady($lesson),
            ],
            'course' => ['id' => $lesson->course?->id, 'title' => $lesson->course?->title, 'slug' => $lesson->course?->slug],
            // Chỉ chủ khóa mới ghi tiến độ (heartbeat); người xem preview thì FE không gọi heartbeat.
            'can_track' => $owned,
            'prev' => $prev,
            'next' => $next,
            'quizzes' => $quizzes['lesson'][$lesson->id] ?? [],
            'progress' => $row === null ? null : [
                'status' => $row->status->value,
                'watched_seconds' => $row->watched_seconds,
                'last_position_seconds' => $row->last_position_seconds,
                'completed_at' => $row->completed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Bài trước/sau theo thứ tự (chương, bài) trong cả khóa; người chưa sở hữu chỉ thấy bài preview.
     *
     * @return array{0: ?array{id: int, title: string}, 1: ?array{id: int, title: string}}
     */
    private function neighbors(Lesson $lesson, bool $owned): array
    {
        $query = Lesson::query()
            ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
            ->where('lessons.course_id', $lesson->course_id)
            ->whereNull('chapters.deleted_at')
            ->orderBy('chapters.position')->orderBy('chapters.id')
            ->orderBy('lessons.position')->orderBy('lessons.id');

        if (! $owned) {
            $query->where('lessons.is_preview', true);
        }

        $list = $query->get(['lessons.id', 'lessons.title'])->values();
        $index = $list->search(fn ($l) => (int) $l->id === (int) $lesson->getKey());

        if ($index === false) {
            return [null, null];
        }

        $map = fn ($l) => $l === null ? null : ['id' => (int) $l->id, 'title' => (string) $l->title];

        return [$map($list->get($index - 1)), $map($list->get($index + 1))];
    }

    /**
     * @return array<int, array{status: string, accessed: int}> lesson_id => trạng thái
     */
    private function progressMap(int $userId, int $courseId): array
    {
        $map = [];
        LessonProgress::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->get(['lesson_id', 'status', 'last_accessed_at'])
            ->each(function (LessonProgress $p) use (&$map): void {
                $map[(int) $p->lesson_id] = ['status' => $p->status->value, 'accessed' => $p->last_accessed_at->getTimestamp()];
            });

        return $map;
    }

    /**
     * Bài gần nhất chưa xong; nếu bài gần nhất đã xong thì bài chưa xong kế tiếp (rồi vòng về đầu); xong hết thì
     * bài gần nhất; chưa học gì thì bài đầu (US-006 AC6).
     *
     * @param  list<int>  $ordered
     * @param  array<int, array{status: string, accessed: int}>  $progress
     */
    public function resumeLessonId(array $ordered, array $progress): ?int
    {
        if ($ordered === []) {
            return null;
        }

        $recentId = null;
        $recentAt = -1;
        foreach ($ordered as $id) {
            if (isset($progress[$id]) && $progress[$id]['accessed'] >= $recentAt) {
                $recentAt = $progress[$id]['accessed'];
                $recentId = $id;
            }
        }

        if ($recentId === null) {
            return $ordered[0];
        }

        $done = fn (int $id) => ($progress[$id]['status'] ?? null) === LessonProgressStatus::Completed->value;

        if (! $done($recentId)) {
            return $recentId;
        }

        $start = (int) array_search($recentId, $ordered, true);
        $count = count($ordered);
        for ($i = 1; $i < $count; $i++) {
            $id = $ordered[($start + $i) % $count];
            if (! $done($id)) {
                return $id;
            }
        }

        return $recentId;
    }

    private function videoReady(Lesson $lesson): bool
    {
        return match ($lesson->video_source) {
            VideoSource::ExternalLink => $lesson->external_video_id !== null,
            VideoSource::Upload => $lesson->videoAsset?->status === VideoAssetStatus::Ready,
            default => false,
        };
    }

    /**
     * Quiz gắn với chương/bài (chỉ đếm câu hỏi, không có nội dung/đáp án).
     *
     * @return array{chapter: array<int, list<array<string, mixed>>>, lesson: array<int, list<array<string, mixed>>>}
     */
    private function quizzes(int $courseId, ?int $lessonId = null): array
    {
        /** @var Collection<int, Quiz> $quizzes */
        $quizzes = Quiz::query()
            ->where('course_id', $courseId)
            ->when($lessonId !== null, fn ($q) => $q->where('lesson_id', $lessonId))
            ->withCount('questions')
            ->orderBy('position')->orderBy('id')
            ->get();

        $out = ['chapter' => [], 'lesson' => []];
        foreach ($quizzes as $quiz) {
            $item = [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'question_count' => (int) $quiz->questions_count,
            ];
            if ($quiz->lesson_id !== null) {
                $out['lesson'][(int) $quiz->lesson_id][] = $item;
            } else {
                $out['chapter'][(int) $quiz->chapter_id][] = $item;
            }
        }

        return $out;
    }
}
