<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * US-009 phân quyền: admin/quản lý trang quản trị mọi khóa; giáo viên chỉ tạo khóa mới và xem/sửa khóa mình có
 * tên trong `course_teacher` (BR6, AC6, AC7). Publish/xoá/gán giáo viên/thứ tự nổi bật chỉ staff (BR2, BR8).
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    public function view(User $user, Course $course): bool
    {
        return $user->isStaff() || $this->isAssignedTeacher($user, $course);
    }

    public function create(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    public function update(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    public function publish(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    public function manageTeachers(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    public function manageOrder(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /** Nội dung chương/bài/quiz (T09+): theo khóa gốc của bản ghi con. */
    public function manageContent(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    private function isAssignedTeacher(User $user, Course $course): bool
    {
        return $user->isTeacher()
            && $course->teachers()->whereKey($user->getKey())->exists();
    }
}
