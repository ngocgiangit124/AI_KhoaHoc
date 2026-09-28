<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * US-011 §Phân quyền — Admin/Quản lý trang: xem/tạo/sửa/ẩn/xoá; Giáo Viên:
 * chỉ xem (để chọn chuyên đề khi tạo khóa học — US-009); middleware `role`
 * trên route đã chặn Học Sinh/khách ở lớp thô (api-contract §1.3).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Subject $subject): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $user->isStaff();
    }

    public function updateStatus(User $user, Subject $subject): bool
    {
        return $user->isStaff();
    }
}
