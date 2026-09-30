<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * US-009 §Phân quyền, BR2/BR6/BR8/BR9, api-contract §2.5.
 *
 * - Admin/Quản lý trang: toàn quyền trên MỌI khóa học.
 * - Giáo Viên: chỉ xem/sửa khóa học mình CÓ TÊN trong `course_teacher` (BR6);
 *   được tự tạo khóa học mới (BR8); KHÔNG BAO GIỜ xoá/xuất bản/ngừng bán
 *   (BR2), và KHÔNG tự gán/gỡ giáo viên phụ trách hay đổi `manual_order` —
 *   đây là các thao tác cấp quản trị (BR9: Quản lý trang gần như Admin,
 *   Giáo Viên thì không).
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->isTeacher() && $this->isAssignedTeacher($user, $course);
    }

    public function create(User $user): bool
    {
        return $user->isStaff() || $user->isTeacher();
    }

    /**
     * BR9 — Sửa nội dung/thông tin chung: staff luôn được; Giáo Viên chỉ khi
     * được gán (BR6). `UpdateCourseRequest` tự giới hạn thêm field nào Giáo
     * Viên được đổi (giá/lớp khi đã từng publish) — không thuộc phạm vi
     * Policy (Policy chỉ quyết định CÓ được vào action hay không).
     */
    public function update(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    /**
     * BR2/AC4/AC5 — chỉ Admin/Quản lý trang được xoá (kể cả khóa học Giáo
     * Viên tự tạo).
     */
    public function delete(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /**
     * BR2 — dùng CHUNG cho publish VÀ unpublish (api-contract §2.5:
     * `CoursePolicy@publish` cho cả 2 route `/publish` và `/unpublish`).
     * "Giáo Viên không có quyền publish, kể cả với khóa học do chính mình
     * tạo."
     */
    public function publish(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /**
     * Gán/gỡ giáo viên phụ trách — quyết định "duyệt" ai được truy cập nội
     * dung khóa học là quyền quản trị (BR9), không giao cho chính Giáo Viên
     * đang phụ trách (tránh 1 GV tự ý thêm/gỡ đồng nghiệp khỏi khóa học).
     */
    public function manageTeachers(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /**
     * `manual_order` chỉ ảnh hưởng thứ tự "Nổi bật" ở danh mục công khai
     * (US-002 BR6) — quyết định marketing/biên tập của Admin/Quản lý trang.
     */
    public function updateManualOrder(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /**
     * T09 — chương/bài (và sau này video, quiz — T11, T21): staff luôn được;
     * Giáo Viên chỉ khóa mình CÓ TÊN trong `course_teacher` (BR6, ADR-004 §3
     * "Chương, bài, video, quiz (`manageContent`)"). Luôn kiểm trên KHÓA GỐC
     * (`{course}` của route đã `scopeBindings` với chapter/lesson con) — S5.
     */
    public function manageContent(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    private function isAssignedTeacher(User $user, Course $course): bool
    {
        return $course->teachers()->whereKey($user->getKey())->exists();
    }
}
