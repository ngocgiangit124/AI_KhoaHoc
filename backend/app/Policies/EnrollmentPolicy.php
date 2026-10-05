<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * US-012: học sinh xin học cho chính mình; admin/quản lý trang duyệt mọi khóa; giáo viên chỉ khóa mình
 * phụ trách (`course_teacher`). Mọi kiểm tra theo `$enrollment->course`, không tin ID từ request.
 */
class EnrollmentPolicy
{
    public function requestFree(User $user, Course $course): bool
    {
        return $user->isStudent();
    }

    public function viewAnyRequests(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    /** Xem yêu cầu của 1 khóa cụ thể (lọc `course_id`): giáo viên không phụ trách → 403 (AC6). */
    public function viewCourseRequests(User $user, int $courseId): bool
    {
        return $user->isStaff() || ($user->isTeacher() && $this->teaches($user, $courseId));
    }

    public function decide(User $user, Enrollment $enrollment): bool
    {
        return $this->viewCourseRequests($user, (int) $enrollment->course_id);
    }

    private function teaches(User $user, int $courseId): bool
    {
        return $user->isTeacher()
            && Course::withTrashed()->whereKey($courseId)->whereHas('teachers', fn ($q) => $q->whereKey($user->getKey()))->exists();
    }
}
