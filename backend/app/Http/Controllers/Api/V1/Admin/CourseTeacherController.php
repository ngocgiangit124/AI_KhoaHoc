<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Course\CourseTeachersRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Services\Courses\CourseTeacherService;

class CourseTeacherController extends Controller
{
    public function __construct(private readonly CourseTeacherService $teachers, private readonly CourseService $courses) {}

    public function update(CourseTeachersRequest $request, Course $course): CourseResource
    {
        /** @var User $user */
        $user = $request->user();

        $this->teachers->sync($course, array_map('intval', $request->validated('teacher_ids')), $user);

        return new CourseResource($this->courses->loadDetail($course));
    }
}
