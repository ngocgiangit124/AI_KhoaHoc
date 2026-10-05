<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Services\Courses\CourseService;
use Illuminate\Support\Facades\Gate;

/** Xuất bản / ngừng bán (US-009 BR2, BR3): chỉ staff. */
class CoursePublicationController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    public function publish(Course $course): CourseResource
    {
        Gate::authorize('publish', $course);

        return $this->detail($this->courses->publish($course));
    }

    public function unpublish(Course $course): CourseResource
    {
        Gate::authorize('publish', $course);

        return $this->detail($this->courses->unpublish($course));
    }

    private function detail(Course $course): CourseResource
    {
        return new CourseResource($this->courses->loadDetail($course));
    }
}
