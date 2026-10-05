<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\LessonAccessService;

/**
 * `watch`: học sinh chủ khóa (hoặc bài preview của khóa published); staff và giáo viên được gán xem thử để kiểm tra
 * nội dung (US-006 phân quyền). `preview`: bài phát công khai, KHÔNG cần đăng nhập (nhận `?User`).
 * Controller học sinh dùng thẳng LessonAccessService để trả đúng mã lỗi COURSE_NOT_OWNED.
 */
class LessonPolicy
{
    public function __construct(private readonly LessonAccessService $access) {}

    public function watch(User $user, Lesson $lesson): bool
    {
        if ($user->isStaff()) {
            return $this->access->lessonIsLive($lesson);
        }

        if ($user->isTeacher()) {
            return $this->access->lessonIsLive($lesson)
                && $lesson->course !== null
                && $lesson->course->teachers()->whereKey($user->getKey())->exists();
        }

        return $this->access->canWatch($user, $lesson);
    }

    public function preview(?User $user, Lesson $lesson): bool
    {
        return $this->access->isPublicPreview($lesson);
    }
}
