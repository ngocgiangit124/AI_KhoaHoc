<?php

namespace App\Services\Curriculum;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;

/**
 * Bất biến nghiệp vụ khi XOÁ nội dung (chương/bài) — dùng chung cho
 * `ChapterService` và `LessonService` để 2 đường xoá không thể lách nhau
 * (review-T09 R1, R2). PHẢI gọi trong `DB::transaction`, SAU khi đã
 * `ContentLock::course()` (hàng `courses` đang bị khoá nên `publish`/`unpublish`
 * của `CourseService` không chen vào giữa lúc kiểm và lúc xoá).
 */
final class ContentGuard
{
    /**
     * US-009 BR4 / "Trường hợp biên & lỗi": khóa đang có học sinh `active`
     * (đang học) thì không được xoá bài/chương còn bài.
     */
    public static function assertNoActiveLearners(Course $course, string $code, string $message): void
    {
        $hasActive = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();

        if ($hasActive) {
            throw new DomainException(code: $code, message: $message, status: 409);
        }
    }

    /**
     * US-009 BR3 là bất biến của trạng thái đang bán: khóa `published` phải
     * còn ít nhất 1 bài (còn bài thì chắc chắn còn chương). Không áp cho
     * `draft`/`unpublished`.
     *
     * @param  list<int>  $lessonIdsBeingDeleted
     */
    public static function assertPublishedKeepsContent(Course $lockedCourse, array $lessonIdsBeingDeleted): void
    {
        if ($lessonIdsBeingDeleted === [] || $lockedCourse->status !== CourseStatus::Published) {
            return;
        }

        $remaining = Lesson::query()
            ->where('course_id', $lockedCourse->getKey())
            ->whereNotIn('id', $lessonIdsBeingDeleted)
            ->exists();

        if (! $remaining) {
            throw new DomainException(
                code: 'COURSE_WOULD_BE_EMPTY',
                message: 'Khóa học đang bán cần còn ít nhất 1 bài học. Hãy ngừng bán trước khi xoá bài học cuối cùng.',
                status: 409,
            );
        }
    }
}
