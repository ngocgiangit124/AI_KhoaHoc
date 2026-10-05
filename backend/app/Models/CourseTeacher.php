<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot khóa học ↔ giáo viên: chỉ có `created_at` (data-model §3.2). Ràng buộc "role giao_vien" và
 * "tối thiểu 1 giáo viên" do CourseTeacherService (T08) bảo đảm.
 */
class CourseTeacher extends Pivot
{
    public const UPDATED_AT = null;

    protected $table = 'course_teacher';
}
