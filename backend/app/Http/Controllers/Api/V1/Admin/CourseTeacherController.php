<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncCourseTeachersRequest;
use App\Http\Resources\Admin\CourseResource;
use App\Models\Course;
use App\Services\Courses\CourseTeacherService;

/**
 * `PUT /admin/courses/{course}/teachers` (US-009 BR6, api-contract §2.5).
 * Phân quyền chạy trước ở middleware `can:manageTeachers,course` của route.
 */
class CourseTeacherController extends Controller
{
    public function __construct(private readonly CourseTeacherService $teachers) {}

    public function update(SyncCourseTeachersRequest $request, Course $course): CourseResource
    {
        $course = $this->teachers->sync($course, $request->validated('teacher_ids'), $request->user());

        return CourseResource::make($course->load('subjects'));
    }
}
