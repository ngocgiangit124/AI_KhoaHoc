<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CurriculumOrderRequest;
use App\Http\Resources\Admin\ChapterResource;
use App\Models\Course;
use App\Services\Curriculum\CurriculumOrderService;
use Illuminate\Http\JsonResponse;

/**
 * `PUT /admin/courses/{course}/curriculum/order` (US-009 BR7/AC8, S5).
 * `can:manageContent,course` chạy TRƯỚC validate. Trả về cây mới.
 */
class CurriculumOrderController extends Controller
{
    public function __construct(private readonly CurriculumOrderService $order) {}

    public function update(CurriculumOrderRequest $request, Course $course): JsonResponse
    {
        /** @var list<array{chapter_id: int, lesson_ids: list<int>}> $items */
        $items = array_values($request->validated());

        $this->order->reorder($course, $items);

        $chapters = $course->chapters()
            ->with(['lessons' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // `withoutWrapping()` toàn cục nên bọc `data` thủ công (như TeacherController).
        return response()->json(['data' => ChapterResource::collection($chapters)->resolve()]);
    }
}
