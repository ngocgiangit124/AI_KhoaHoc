<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * US-011 BR4: admin/quản lý trang quản lý chuyên đề; giáo viên chỉ xem (để chọn khi tạo/sửa khóa học).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
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
}
