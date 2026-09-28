<?php

namespace App\Services\Catalog;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * GET /courses/{slug}/viewer-state (US-003, api-contract §2.1) — tách khỏi
 * `show` để `show` cache được (S16).
 */
class CourseViewerStateService
{
    /**
     * @return array{viewer_state: string, resume_lesson_id: int|null}
     */
    public function resolve(Course $course, User $user): array
    {
        /** @var Enrollment|null $enrollment */
        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('live_flag')
            ->first();

        if ($enrollment?->status === EnrollmentStatus::Active) {
            return [
                'viewer_state' => 'owned',
                'resume_lesson_id' => $this->resumeLessonId($course, $user),
            ];
        }

        if ($enrollment?->status === EnrollmentStatus::PendingApproval) {
            return ['viewer_state' => 'pending_approval', 'resume_lesson_id' => null];
        }

        // TODO(T16): kiểm giỏ hàng của user — nếu khóa đang trong giỏ, trả
        // 'in_cart' thay vì 'can_buy' (chưa có bảng `cart_items` ở T07/T10).
        if ((int) $course->price === 0) {
            return ['viewer_state' => 'can_register_free', 'resume_lesson_id' => null];
        }

        return ['viewer_state' => 'can_buy', 'resume_lesson_id' => null];
    }

    /**
     * US-003 BR6 — bài học gần nhất theo `lesson_progress.last_accessed_at`;
     * chưa học bài nào → bài đầu tiên theo (chapter.position, lesson.position).
     */
    private function resumeLessonId(Course $course, User $user): ?int
    {
        $lastProgress = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderByDesc('last_accessed_at')
            ->first();

        if ($lastProgress !== null) {
            return $lastProgress->lesson_id;
        }

        $firstLesson = Lesson::query()
            ->where('lessons.course_id', $course->id)
            ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
            ->orderBy('chapters.position')
            ->orderBy('lessons.position')
            ->select('lessons.*')
            ->first();

        return $firstLesson?->id;
    }
}
