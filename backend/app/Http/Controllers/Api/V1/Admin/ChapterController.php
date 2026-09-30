<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChapterRequest;
use App\Http\Resources\Admin\ChapterResource;
use App\Models\Chapter;
use App\Models\Course;
use App\Services\Curriculum\ChapterService;
use Illuminate\Http\Response;

/**
 * `/admin/courses/{course}/chapters[/{chapter}]` (US-009, api-contract §2.5).
 * Route `scopeBindings` (chương phải thuộc `{course}` — S5) + middleware
 * `can:manageContent,course` chạy TRƯỚC validate.
 */
class ChapterController extends Controller
{
    public function __construct(private readonly ChapterService $chapters) {}

    public function store(ChapterRequest $request, Course $course): ChapterResource
    {
        $chapter = $this->chapters->create($course, $request->validated());

        return ChapterResource::make($chapter);
    }

    public function update(ChapterRequest $request, Course $course, Chapter $chapter): ChapterResource
    {
        $chapter = $this->chapters->update($course, $chapter, $request->validated());

        return ChapterResource::make($chapter);
    }

    public function destroy(Course $course, Chapter $chapter): Response
    {
        $this->chapters->delete($course, $chapter);

        return response()->noContent();
    }
}
