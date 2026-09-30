<?php

namespace App\Services\Courses;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * BR6 — quản lý danh sách giáo viên phụ trách của 1 khóa học (US-009).
 * `SyncCourseTeachersRequest` đã kiểm role + tối thiểu 1 phần tử; các kiểm
 * tra dưới đây là phòng thủ thứ 2 (defense in depth — không tin tuyệt đối 1
 * lớp validate duy nhất cho 1 hành vi cấp quyền quan trọng).
 */
class CourseTeacherService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<int>  $teacherIds
     */
    public function sync(Course $course, array $teacherIds, User $actor): Course
    {
        $teacherIds = array_values(array_unique($teacherIds));

        if ($teacherIds === []) {
            throw new DomainException(
                code: 'TEACHER_MIN_REQUIRED',
                message: 'Khóa học cần có ít nhất 1 giáo viên phụ trách.',
                status: 422,
            );
        }

        $validTeacherCount = User::query()
            ->whereIn('id', $teacherIds)
            ->where('role', UserRole::Teacher)
            ->count();

        if ($validTeacherCount !== count($teacherIds)) {
            throw new DomainException(
                code: 'INVALID_TEACHER_ROLE',
                message: 'Chỉ có thể chọn tài khoản có vai trò Giáo viên.',
                status: 422,
            );
        }

        return DB::transaction(function () use ($course, $teacherIds, $actor): Course {
            /** @var list<int> $current */
            $current = $course->teachers()->pluck('users.id')->all();

            $toDetach = array_diff($current, $teacherIds);
            $toAttach = array_diff($teacherIds, $current);

            if ($toDetach !== []) {
                $course->teachers()->detach($toDetach);
            }

            if ($toAttach !== []) {
                $pivot = [];
                foreach ($toAttach as $teacherId) {
                    $pivot[$teacherId] = ['added_by' => $actor->getKey(), 'created_at' => now()];
                }
                $course->teachers()->attach($pivot);
            }

            $this->auditLogger->log('course.teachers.sync', $course, [
                'teacher_ids' => ['before' => $current,'after' => $teacherIds],
            ]);

            return $course->load('teachers');
        });
    }
}
