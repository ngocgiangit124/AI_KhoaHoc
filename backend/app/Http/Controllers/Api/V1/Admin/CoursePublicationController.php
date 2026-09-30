<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CourseResource;
use App\Models\Course;
use App\Services\Courses\CourseService;

/**
 * `POST /admin/courses/{course}/publish` · `/unpublish` (US-009 BR2/BR3,
 * api-contract §2.5). Phân quyền chạy trước ở middleware
 * `can:publish,course` của route (dùng CHUNG ability cho cả 2 action).
 */
class CoursePublicationController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    public function publish(Course $course): CourseResource
    {
        return CourseResource::make($this->courses->publish($course)->load(['subjects', 'teachers']));
    }

    public function unpublish(Course $course): CourseResource
    {
        return CourseResource::make($this->courses->unpublish($course)->load(['subjects', 'teachers']));
    }
}
