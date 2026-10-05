<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Services\Learning\LearningOutlineService;
use App\Services\Learning\LessonAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnCourseController extends Controller
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly LearningOutlineService $outline,
    ) {}

    public function show(Request $request, Course $course): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->access->assertCanLearnCourse($user, $course);

        return response()->json($this->outline->outline($user, $course));
    }
}
