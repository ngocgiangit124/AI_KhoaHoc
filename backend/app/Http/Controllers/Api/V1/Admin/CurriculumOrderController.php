<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\CurriculumOrderRequest;
use App\Http\Resources\ChapterResource;
use App\Models\Course;
use App\Services\Courses\CurriculumService;
use Illuminate\Http\JsonResponse;

class CurriculumOrderController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function update(CurriculumOrderRequest $request, Course $course): JsonResponse
    {
        $this->curriculum->reorder($course, $request->items());

        return response()->json([
            'course_id' => $course->id,
            'chapters' => ChapterResource::collection($this->curriculum->tree($course))->resolve($request),
        ]);
    }
}
