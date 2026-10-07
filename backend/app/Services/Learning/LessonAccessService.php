<?php

namespace App\Services\Learning;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;

/**
 * Quyền xem khóa/bài của học sinh (US-006 BR1/BR4, ADR-002 §4). Nguồn duy nhất cho T13, T22 (quiz), T23.
 *
 * - "Sở hữu" = có enrollment `active` với khóa (khóa bị gỡ xuất bản vẫn giữ quyền - BR4).
 * - Người chưa sở hữu chỉ xem được bài preview của khóa đang `published`.
 * - Khóa chưa published mà không sở hữu: 404 (không để dò ID bài của khóa nháp).
 */
class LessonAccessService
{
    public function ownsCourse(int $userId, int $courseId): bool
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();
    }

    /** @throws DomainException 403 COURSE_NOT_OWNED */
    public function assertOwnsCourse(User $user, int $courseId): void
    {
        if (! $this->ownsCourse((int) $user->getKey(), $courseId)) {
            throw $this->notOwned($courseId);
        }
    }

    /**
     * Quyền vào trang học của khóa: sở hữu thì cho; chưa sở hữu và khóa không published thì 404, còn lại 403.
     *
     * @throws DomainException
     */
    public function assertCanLearnCourse(User $user, Course $course): void
    {
        if ($this->ownsCourse((int) $user->getKey(), (int) $course->getKey())) {
            return;
        }

        throw $course->status === CourseStatus::Published ? $this->notOwned((int) $course->getKey()) : $this->notFound();
    }

    /**
     * Xem bài (outline/chi tiết/playback). Trả `true` nếu xem với tư cách chủ khóa, `false` nếu chỉ là preview.
     *
     * @throws DomainException 404 (bài/khóa không còn hoặc khóa nháp), 403 COURSE_NOT_OWNED
     */
    public function assertCanWatch(User $user, Lesson $lesson): bool
    {
        if (! $this->lessonIsLive($lesson)) {
            throw $this->notFound();
        }

        if ($this->ownsCourse((int) $user->getKey(), (int) $lesson->course_id)) {
            return true;
        }

        $course = $lesson->course;

        if ($course === null || $course->status !== CourseStatus::Published) {
            throw $this->notFound();
        }

        if (! $lesson->is_preview) {
            throw $this->notOwned((int) $lesson->course_id);
        }

        return false;
    }

    public function canWatch(User $user, Lesson $lesson): bool
    {
        try {
            $this->assertCanWatch($user, $lesson);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** Bài phát công khai (không đăng nhập): is_preview, chưa xoá (bài/chương/khóa), khóa published. */
    public function isPublicPreview(Lesson $lesson): bool
    {
        return $lesson->is_preview
            && $this->lessonIsLive($lesson)
            && $lesson->course?->status === CourseStatus::Published;
    }

    /** Bài, chương và khóa đều chưa bị xoá mềm. */
    public function lessonIsLive(Lesson $lesson): bool
    {
        return ! $lesson->trashed()
            && $lesson->course !== null
            && Chapter::query()->whereKey($lesson->chapter_id)->exists();
    }

    /**
     * Người KHÔNG sở hữu khóa: khóa published -> 403 COURSE_NOT_OWNED (kèm course); khóa nháp/ẩn -> 404 (không để dò ID
     * bài của khóa chưa công bố). Dùng cho đường ghi tiến độ (heartbeat, complete).
     */
    public function denyNotOwned(int $courseId): DomainException
    {
        $published = Course::query()->whereKey($courseId)->where('status', CourseStatus::Published->value)->exists();

        return $published ? $this->notOwned($courseId) : $this->notFound();
    }

    /**
     * 403 COURSE_NOT_OWNED. Kèm `errors.course {id, slug, title}` để web chuyển về trang chi tiết, CHỈ khi khóa đang
     * published (khóa nháp/ẩn/đã xoá không lộ slug/title).
     */
    public function notOwned(?int $courseId = null): DomainException
    {
        $context = [];

        if ($courseId !== null) {
            $course = Course::query()->whereKey($courseId)->where('status', CourseStatus::Published->value)->first(['id', 'slug', 'title']);

            if ($course !== null) {
                $context['course'] = ['id' => $course->id, 'slug' => $course->slug, 'title' => $course->title];
            }
        }

        return new DomainException('COURSE_NOT_OWNED', 'Bạn chưa sở hữu khóa học này.', 403, $context);
    }

    public function notFound(): DomainException
    {
        // Cùng message với 404 mặc định (ApiExceptionRenderer) để không phân biệt được ID khoá nháp với ID không tồn tại (QA SLN5 BUG-1).
        return new DomainException('NOT_FOUND', 'Không tìm thấy tài nguyên.', 404);
    }
}
