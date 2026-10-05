<?php

namespace App\Services\Courses;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gán giáo viên phụ trách (US-009 BR6/BR8). Nơi duy nhất ghi `course_teacher`: kiểm role `giao_vien` + đang
 * hoạt động, tối thiểu 1 giáo viên (khoá dòng khóa học để hai yêu cầu gỡ đồng thời không để khóa mồ côi).
 */
class CourseTeacherService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Đặt danh sách giáo viên của khóa thành đúng `$teacherIds`. Gọi trong hoặc ngoài transaction.
     *
     * @param  list<int>  $teacherIds
     */
    public function sync(Course $course, array $teacherIds, User $actor): void
    {
        $teacherIds = array_values(array_unique(array_map('intval', $teacherIds)));

        if ($teacherIds === []) {
            throw ValidationException::withMessages(['teacher_ids' => 'Khóa học cần tối thiểu 1 giáo viên phụ trách.']);
        }

        DB::transaction(function () use ($course, $teacherIds, $actor): void {
            // Khoá dòng khóa học trước khi đọc danh sách hiện tại (tuần tự hoá các lần gán đồng thời).
            Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();

            $current = DB::table('course_teacher')->where('course_id', $course->getKey())->pluck('user_id')
                ->map(fn ($id) => (int) $id)->all();

            // Chỉ giáo viên MỚI được thêm phải đang hoạt động; người đã gán sẵn được giữ nguyên dù bị khoá
            // (staff gửi lại đủ danh sách cũ không bị 422; muốn gỡ thì bỏ khỏi danh sách).
            $newIds = array_values(array_diff($teacherIds, $current));
            $validNew = $newIds === [] ? [] : User::query()
                ->whereIn('id', $newIds)
                ->where('role', UserRole::Teacher)
                ->where('status', UserStatus::Active)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $stillTeachers = User::query()->whereIn('id', array_intersect($teacherIds, $current))
                ->where('role', UserRole::Teacher)->count();

            if (count($validNew) !== count($newIds) || $stillTeachers !== count(array_intersect($teacherIds, $current))) {
                throw ValidationException::withMessages(['teacher_ids' => 'Chỉ được gán tài khoản giáo viên đang hoạt động.']);
            }

            $add = array_values(array_diff($teacherIds, $current));
            $remove = array_values(array_diff($current, $teacherIds));

            if ($add === [] && $remove === []) {
                return;
            }

            if ($remove !== []) {
                $course->teachers()->detach($remove);
            }

            foreach ($add as $id) {
                $course->teachers()->attach($id, ['added_by' => $actor->getKey()]);
            }

            sort($current);
            $new = $teacherIds;
            sort($new);
            $this->audit->log('course.teachers', $course, ['teacher_ids' => ['from' => $current, 'to' => $new]]);
        });

        $course->unsetRelation('teachers');
    }
}
