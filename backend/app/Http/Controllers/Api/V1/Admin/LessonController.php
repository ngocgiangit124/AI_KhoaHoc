<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LessonRequest;
use App\Http\Resources\Admin\LessonResource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Curriculum\LessonService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/admin/courses/{course}/chapters/{chapter}/lessons[/{lesson}]` (US-009 BR10,
 * api-contract §2.5). Route `scopeBindings` (bài thuộc chương, chương thuộc
 * khóa — S5) + `can:manageContent,course` chạy TRƯỚC validate.
 */
class LessonController extends Controller
{
    public function __construct(private readonly LessonService $lessons) {}

    public function store(LessonRequest $request, Course $course, Chapter $chapter): LessonResource
    {
        $lesson = $this->lessons->create($course, $chapter, $request->validated(), $request->user());

        return LessonResource::make($lesson);
    }

    public function update(LessonRequest $request, Course $course, Chapter $chapter, Lesson $lesson): LessonResource
    {
        $lesson = $this->lessons->update($course, $chapter, $lesson, $request->validated(), $request->user());

        return LessonResource::make($lesson);
    }

    public function destroy(Request $request, Course $course, Chapter $chapter, Lesson $lesson): Response
    {
        $this->lessons->delete($course, $chapter, $lesson, $request->user());

        return response()->noContent();
    }
}
