<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\LessonRequest;
use App\Http\Resources\LessonResource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Courses\CurriculumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class LessonController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function store(LessonRequest $request, Course $course, Chapter $chapter): JsonResponse
    {
        $lesson = $this->curriculum->createLesson($course, $chapter, $request->validated());

        return (new LessonResource($lesson->load('videoAsset:id,status')))->response($request)->setStatusCode(201);
    }

    public function update(LessonRequest $request, Course $course, Chapter $chapter, Lesson $lesson): LessonResource
    {
        return new LessonResource(
            $this->curriculum->updateLesson($course, $chapter, $lesson, $request->validated())->load('videoAsset:id,status'),
        );
    }

    public function destroy(Course $course, Chapter $chapter, Lesson $lesson): Response
    {
        Gate::authorize('manageContent', $course);

        $this->curriculum->deleteLesson($course, $chapter, $lesson);

        return response()->noContent();
    }
}
