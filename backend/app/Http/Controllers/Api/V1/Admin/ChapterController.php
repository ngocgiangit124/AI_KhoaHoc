<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\ChapterRequest;
use App\Http\Resources\ChapterResource;
use App\Models\Chapter;
use App\Models\Course;
use App\Services\Courses\CurriculumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ChapterController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    /** Cây chương/bài của khóa (cho màn kéo thả). Quyền xem = quyền quản lý nội dung. */
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageContent', $course);

        return response()->json([
            'course_id' => $course->id,
            'chapters' => ChapterResource::collection($this->curriculum->tree($course))->resolve($request),
        ]);
    }

    public function store(ChapterRequest $request, Course $course): JsonResponse
    {
        $chapter = $this->curriculum->createChapter($course, $request->validated('title'));

        return (new ChapterResource($chapter->load('lessons')))->response($request)->setStatusCode(201);
    }

    public function update(ChapterRequest $request, Course $course, Chapter $chapter): ChapterResource
    {
        return new ChapterResource($this->curriculum->updateChapter($course, $chapter, $request->validated('title'))->load('lessons'));
    }

    public function destroy(Course $course, Chapter $chapter): Response
    {
        Gate::authorize('manageContent', $course);

        $this->curriculum->deleteChapter($course, $chapter);

        return response()->noContent();
    }
}
