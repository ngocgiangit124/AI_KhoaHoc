<?php

namespace App\Services\Teachers;

use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Đọc hồ sơ giáo viên cho màn quản trị (chỉ đọc; ghi nằm ở `TeacherProfileService`). Mỗi `User` trả ra đã có quan hệ
 * `teacherProfile.updatedBy` và thuộc tính `published_courses_count` (đếm bằng subquery, không N+1) cho
 * `TeacherProfileResource`.
 */
class TeacherProfileReader
{
    /**
     * Người được quản lý ở màn hồ sơ: mọi `giao_vien` (kể cả bị khoá) VÀ người đã đổi vai trò nhưng còn dòng hồ sơ (BR9:
     * để admin tắt cờ trang chủ, vì họ vẫn chiếm 1 trong `homepage_max` suất; L1: để admin xoá ảnh hộ).
     *
     * @return Builder<User>
     */
    private function managedQuery(): Builder
    {
        return $this->withProfileData(User::query()
            ->leftJoin('teacher_profiles as tp', 'tp.user_id', '=', 'users.id')
            ->select('users.*')
            ->where(fn (Builder $w) => $w
                ->where('users.role', UserRole::Teacher->value)
                ->orWhereNotNull('tp.user_id')));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function withProfileData(Builder $query): Builder
    {
        return $query
            ->with(['teacherProfile', 'teacherProfile.updatedBy:id,name'])
            ->withCount(['taughtCourses as published_courses_count' => fn (Builder|Relation $q) => $q
                ->where('courses.status', CourseStatus::Published->value)]);
    }

    /** User thuộc diện quản lý (xem `managedQuery`), ngược lại null (→ 404). */
    public function findManaged(int $id): ?User
    {
        return $this->managedQuery()->where('users.id', $id)->first();
    }

    /** Nạp lại một user đã biết (sau khi ghi) với đủ dữ liệu cho Resource. */
    public function load(int $id): User
    {
        return $this->withProfileData(User::query())->findOrFail($id);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?string $q, bool $onlyHomepage, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->managedQuery();

        if ($q !== null && trim($q) !== '') {
            $query->where('users.name', 'like', Like::contains($q));
        }

        if ($onlyHomepage) {
            $query->where('tp.show_on_homepage', true);
        }

        // Đang bật trước (thứ tự tăng dần, chưa đặt thứ tự xếp sau), rồi theo tên, id.
        return $query
            ->orderByRaw('COALESCE(tp.show_on_homepage, 0) DESC')
            ->orderByRaw('(COALESCE(tp.show_on_homepage, 0) = 1 AND tp.homepage_order IS NULL)')
            ->orderByRaw('CASE WHEN COALESCE(tp.show_on_homepage, 0) = 1 THEN tp.homepage_order END')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate($perPage, page: $page);
    }

    /** Số dòng đang bật hiển thị trang chủ (kể cả người bị khoá/đã đổi vai trò). */
    public function enabledCount(): int
    {
        return TeacherProfile::query()->where('show_on_homepage', true)->count();
    }
}
