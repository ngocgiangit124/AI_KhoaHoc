<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\LearningOutlineService;
use App\Services\Learning\LessonAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly LearningOutlineService $outline,
    ) {}

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $owned = $this->access->assertCanWatch($user, $lesson);

        return response()->json($this->outline->lessonDetail($user, $lesson, $owned));
    }
}
