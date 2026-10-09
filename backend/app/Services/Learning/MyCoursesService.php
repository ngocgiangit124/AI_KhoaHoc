<?php

namespace App\Services\Learning;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonProgressStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Quiz\QuizAttemptService;
use App\Support\StaticUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Khóa học của tôi" (US-008). Mọi số liệu của trang danh sách tính THEO LÔ (số truy vấn không phụ thuộc số khóa):
 * % tiến độ (CourseProgressService::percentsFor), đếm bài, bài học tiếp (cùng luật AC6 với trang học), điểm quiz cao nhất.
 *
 * - Danh sách chính = enrollment `active` (BR3), kể cả khóa đã gỡ xuất bản (BR4); enrollment revoked không hiện.
 * - Sắp xếp: học gần nhất lên đầu (`enrollments.last_accessed_at`, chưa học xếp sau theo `activated_at` mới nhất).
 * - Đang chờ duyệt / bị từ chối trả riêng (không phân trang, tối đa `status_list_limit`).
 */
class MyCoursesService
{
    public function __construct(
        private readonly CourseProgressService $courseProgress,
        private readonly LearningOutlineService $outline,
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    /**
     * @return array{paginator: LengthAwarePaginator<int, Enrollment>, items: list<array<string, mixed>>, pending: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function list(User $user, int $page, int $perPage): array
    {
        $userId = (int) $user->getKey();

        $paginator = Enrollment::query()
            ->from('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('enrollments.user_id', $userId)
            ->where('enrollments.status', EnrollmentStatus::Active->value)
            ->whereNull('courses.deleted_at')
            ->orderByRaw('enrollments.last_accessed_at IS NULL')
            ->orderByDesc('enrollments.last_accessed_at')
            ->orderByDesc('enrollments.activated_at')
            ->orderByDesc('enrollments.id')
            ->select('enrollments.*')
            ->with('course')
            ->paginate($perPage, ['enrollments.*'], 'page', $page);

        /** @var list<Enrollment> $enrollments */
        $enrollments = $paginator->items();
        $courseIds = array_map(fn (Enrollment $e): int => (int) $e->course_id, $enrollments);

        $percents = $this->courseProgress->percentsFor($userId, $courseIds);
        $counts = $this->lessonCounts($userId, $courseIds);
        $resume = $this->resumeLessons($userId, $courseIds);
        $best = $this->bestQuizScores($userId, $courseIds);

        $items = [];
        foreach ($enrollments as $enrollment) {
            $cid = (int) $enrollment->course_id;
            $total = $counts[$cid]['total'] ?? 0;
            $percent = $percents[$cid] ?? 0;

            $items[] = [
                'course' => $this->courseCard($enrollment->course),
                'enrollment' => [
                    'id' => $enrollment->id,
                    'status' => 'active',
                    'activated_at' => $enrollment->activated_at?->toIso8601String(),
                    'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
                ],
                'progress' => [
                    'percent' => $percent,
                    'completed_lessons' => $counts[$cid]['done'] ?? 0,
                    'total_lessons' => $total,
                    // BR2: 100% bài học; khóa 0 bài không bao giờ "hoàn thành" (chưa có nội dung).
                    'is_completed' => $total > 0 && $percent === 100,
                    'has_content' => $total > 0,
                ],
                'resume_lesson_id' => $resume[$cid] ?? null,
                'best_quiz_score' => $best[$cid] ?? null,
            ];
        }

        return [
            'paginator' => $paginator,
            'items' => $items,
            'pending' => $this->statusList($userId, EnrollmentStatus::PendingApproval),
            'rejected' => $this->statusList($userId, EnrollmentStatus::Rejected),
        ];
    }

    /**
     * Tiến độ chi tiết 1 khóa (quyền đã kiểm ở controller). Bài đã xong theo chương, quiz kèm điểm cao nhất.
     *
     * @return array<string, mixed>
     */
    public function progress(User $user, Course $course): array
    {
        $userId = (int) $user->getKey();
        $courseId = (int) $course->getKey();

        $outline = $this->outline->outline($user, $course);

        $progressRows = DB::table('lesson_progress')
            ->where('user_id', $userId)->where('course_id', $courseId)
            ->get(['lesson_id', 'watched_seconds', 'completed_at'])
            ->keyBy('lesson_id');

        $quizRows = Quiz::query()
            ->where('course_id', $courseId)
            ->withCount('questions')
            ->orderBy('position')->orderBy('id')
            ->get();
        $best = $this->quizAttempts->bestScoresForCourse($userId, $courseId, $quizRows->pluck('id')->map(fn ($i): int => (int) $i)->all());
        $bestAttemptIds = $this->quizAttempts->bestAttemptIdsForCourse($userId, $courseId, $quizRows->pluck('id')->map(fn ($i): int => (int) $i)->all());
        $attemptCounts = DB::table('quiz_attempts')
            ->where('user_id', $userId)->where('course_id', $courseId)->whereNotNull('submitted_at')
            ->groupBy('quiz_id')->selectRaw('quiz_id, COUNT(*) as n')->pluck('n', 'quiz_id');

        $totalLessons = 0;
        $doneLessons = 0;
        $chapters = [];
        foreach ($outline['chapters'] as $chapter) {
            $lessons = [];
            $chapterDone = 0;
            foreach ($chapter['lessons'] as $lesson) {
                $row = $progressRows->get($lesson['id']);
                $completed = $lesson['status'] === LessonProgressStatus::Completed->value;
                $chapterDone += $completed ? 1 : 0;
                $lessons[] = [
                    'id' => $lesson['id'],
                    'title' => $lesson['title'],
                    'position' => $lesson['position'],
                    'duration_seconds' => $lesson['duration_seconds'],
                    'status' => $lesson['status'],
                    'watched_seconds' => $row === null ? 0 : (int) $row->watched_seconds,
                    'completed_at' => $row?->completed_at === null ? null : Carbon::parse($row->completed_at)->toIso8601String(),
                ];
            }
            $totalLessons += count($lessons);
            $doneLessons += $chapterDone;
            $chapters[] = [
                'id' => $chapter['id'],
                'title' => $chapter['title'],
                'position' => $chapter['position'],
                'completed_lessons' => $chapterDone,
                'total_lessons' => count($lessons),
                'lessons' => $lessons,
            ];
        }

        $quizzes = $quizRows->map(fn (Quiz $q): array => [
            'id' => $q->id,
            'title' => $q->title,
            'chapter_id' => $q->chapter_id,
            'lesson_id' => $q->lesson_id,
            'question_count' => (int) $q->questions_count,
            'attempted' => isset($best[$q->id]),
            'attempts_count' => (int) ($attemptCounts[$q->id] ?? 0),
            'best_score' => isset($best[$q->id]) ? round($best[$q->id], 2) : null,
            'best_attempt_id' => $bestAttemptIds[$q->id] ?? null,
        ])->values()->all();

        $enrollment = Enrollment::query()
            ->where('user_id', $userId)->where('course_id', $courseId)
            ->where('status', EnrollmentStatus::Active->value)
            ->first(['id', 'activated_at', 'last_accessed_at']);

        $percent = (int) $outline['course_percent'];

        return [
            'course' => $this->courseCard($course),
            'enrollment' => [
                'activated_at' => $enrollment?->activated_at?->toIso8601String(),
                'last_accessed_at' => $enrollment?->last_accessed_at?->toIso8601String(),
            ],
            'progress' => [
                'percent' => $percent,
                'completed_lessons' => $doneLessons,
                'total_lessons' => $totalLessons,
                'is_completed' => $totalLessons > 0 && $percent === 100,
                'has_content' => $totalLessons > 0,
            ],
            'resume_lesson_id' => $outline['resume_lesson_id'],
            'chapters' => $chapters,
            'quizzes' => $quizzes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseCard(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'grade_level' => $course->grade_level,
            'thumbnail_url' => StaticUrl::to($course->thumbnail_path),
            // BR4: khóa đã gỡ xuất bản vẫn hiện; FE dùng cờ này để báo "tạm ngưng bán" nếu cần.
            'is_published' => $course->status === CourseStatus::Published,
        ];
    }

    /**
     * Số bài (chưa xoá) và số bài đã xong cho nhiều khóa — 2 truy vấn.
     *
     * @param  list<int>  $courseIds
     * @return array<int, array{total: int, done: int}>
     */
    private function lessonCounts(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $totals = DB::table('lessons')->whereIn('course_id', $courseIds)->whereNull('deleted_at')
            ->groupBy('course_id')->selectRaw('course_id, COUNT(*) as total')->pluck('total', 'course_id');

        $done = DB::table('lesson_progress as lp')
            ->join('lessons as l', 'l.id', '=', 'lp.lesson_id')
            ->where('lp.user_id', $userId)->whereIn('lp.course_id', $courseIds)
            ->where('lp.status', LessonProgressStatus::Completed->value)
            ->whereNull('l.deleted_at')
            ->groupBy('lp.course_id')->selectRaw('lp.course_id, COUNT(*) as done')->pluck('done', 'lp.course_id');

        $out = [];
        foreach ($courseIds as $id) {
            $out[$id] = ['total' => (int) ($totals[$id] ?? 0), 'done' => (int) ($done[$id] ?? 0)];
        }

        return $out;
    }

    /**
     * Bài học tiếp theo (luật AC6 của LearningOutlineService) cho nhiều khóa: 2 truy vấn (bài theo thứ tự + tiến độ).
     *
     * @param  list<int>  $courseIds
     * @return array<int, int|null>
     */
    private function resumeLessons(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $ordered = [];
        DB::table('lessons')
            ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
            ->whereIn('lessons.course_id', $courseIds)
            ->whereNull('lessons.deleted_at')->whereNull('chapters.deleted_at')
            ->orderBy('lessons.course_id')
            ->orderBy('chapters.position')->orderBy('chapters.id')
            ->orderBy('lessons.position')->orderBy('lessons.id')
            ->get(['lessons.id', 'lessons.course_id'])
            ->each(function ($row) use (&$ordered): void {
                $ordered[(int) $row->course_id][] = (int) $row->id;
            });

        $progress = [];
        DB::table('lesson_progress')
            ->where('user_id', $userId)->whereIn('course_id', $courseIds)
            ->get(['lesson_id', 'course_id', 'status', 'last_accessed_at'])
            ->each(function ($row) use (&$progress): void {
                $progress[(int) $row->course_id][(int) $row->lesson_id] = [
                    'status' => (string) $row->status,
                    'accessed' => Carbon::parse($row->last_accessed_at)->getTimestamp(),
                ];
            });

        $out = [];
        foreach ($courseIds as $id) {
            $out[$id] = $this->outline->resumeLessonId($ordered[$id] ?? [], $progress[$id] ?? []);
        }

        return $out;
    }

    /**
     * Điểm quiz cao nhất (thang 10) của mỗi khóa = max các lượt đã nộp; 1 truy vấn.
     *
     * @param  list<int>  $courseIds
     * @return array<int, float>
     */
    private function bestQuizScores(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        // Join quizzes: bỏ quiz đã xoá mềm để khớp trang chi tiết (R1).
        return DB::table('quiz_attempts as qa')
            ->join('quizzes as q', 'q.id', '=', 'qa.quiz_id')
            ->whereNull('q.deleted_at')
            ->where('qa.user_id', $userId)->whereIn('qa.course_id', $courseIds)
            ->whereNotNull('qa.submitted_at')
            ->groupBy('qa.course_id')->selectRaw('qa.course_id, MAX(qa.score) as best')
            ->pluck('best', 'qa.course_id')
            ->map(fn ($v): float => round((float) $v, 2))
            ->all();
    }

    /**
     * Yêu cầu chờ duyệt / bị từ chối (mới nhất trước), bỏ khóa đã xoá. Khóa bị từ chối mà HS đã xin lại
     * (đang chờ/đang học) thì không liệt kê nữa; mỗi khóa chỉ 1 dòng.
     *
     * @return list<array<string, mixed>>
     */
    private function statusList(int $userId, EnrollmentStatus $status): array
    {
        $limit = (int) config('learning.my_courses.status_list_limit');

        $query = Enrollment::query()
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('enrollments.user_id', $userId)
            ->where('enrollments.status', $status->value)
            ->whereNull('courses.deleted_at')
            ->orderByDesc('enrollments.requested_at')->orderByDesc('enrollments.id')
            ->select('enrollments.*')
            ->with('course');

        if ($status === EnrollmentStatus::Rejected) {
            $query->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('enrollments as e2')
                    ->whereColumn('e2.user_id', 'enrollments.user_id')
                    ->whereColumn('e2.course_id', 'enrollments.course_id')
                    ->where('e2.live_flag', 1);
            });
        }

        $seen = [];
        $out = [];
        foreach ($query->limit($limit * 3)->get() as $enrollment) {
            if (isset($seen[$enrollment->course_id])) {
                continue;
            }
            $seen[$enrollment->course_id] = true;
            $item = [
                'enrollment_id' => $enrollment->id,
                'status' => $status->value,
                'course' => $this->courseCard($enrollment->course),
                'requested_at' => $enrollment->requested_at?->toIso8601String(),
            ];
            if ($status === EnrollmentStatus::Rejected) {
                $item['rejection_reason'] = $enrollment->rejection_reason;
            }
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
