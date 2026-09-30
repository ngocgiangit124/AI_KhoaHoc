<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * US-012 §Phân quyền. `decide` dùng chung cho approve/reject (api-contract
 * §2.5) — kiểm theo `$enrollment->course` (BR4): Admin/Quản lý trang duyệt
 * được mọi khóa; Giáo Viên chỉ duyệt được khóa mình phụ trách
 * (`course_teacher` — US-009), kể cả khi cố truy cập thẳng bằng ID (AC6).
 */
class EnrollmentPolicy
{
    /**
     * BR1 — chỉ khóa `published` + miễn phí (`price = 0`) được đăng ký theo
     * luồng này. Vai trò `hoc_sinh` đã được middleware route đảm bảo
     * (`role:hoc_sinh` — api-contract §1.3); kiểm lại ở đây cho rõ ràng và an
     * toàn nếu Policy được gọi từ nơi khác trong tương lai.
     */
    public function requestFree(User $user, Course $course): bool
    {
        return $user->isStudent()
            && $course->status === CourseStatus::Published
            && (int) $course->price === 0;
    }

    /**
     * BR4/AC7 — Admin/Quản lý trang xem được (có filter `course_id` hay
     * không); Giáo Viên xem được khi KHÔNG lọc (Controller tự giới hạn danh
     * sách theo khóa mình phụ trách) hoặc khi lọc ĐÚNG khóa mình phụ trách
     * (AC6 — lọc khóa KHÔNG phụ trách, kể cả qua URL trực tiếp, bị từ chối).
     */
    public function viewAnyRequests(User $user, ?Course $course = null): bool
    {
        if (! ($user->isStaff() || $user->isTeacher())) {
            return false;
        }

        if ($course === null || $user->isStaff()) {
            return true;
        }

        return $course->teachers()->whereKey($user->getKey())->exists();
    }

    public function decide(User $user, Enrollment $enrollment): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        // Truy vấn qua quan hệ `Course::teachers()` thay vì đọc thuộc tính đã
        // load sẵn trên `$enrollment` — tránh phụ thuộc việc controller có
        // eager-load `course.teachers` hay chưa (an toàn với
        // `Model::preventLazyLoading()` ở local/testing).
        return Course::query()
            ->whereKey($enrollment->course_id)
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->exists();
    }
}
