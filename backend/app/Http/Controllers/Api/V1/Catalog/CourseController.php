<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CourseSearchRequest;
use App\Http\Resources\Catalog\CourseResource;
use App\Http\Resources\Catalog\CourseSummaryResource;
use App\Models\Course;
use App\Models\User;
use App\Services\Catalog\CourseCatalogService;
use App\Services\Catalog\CourseViewerStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Danh mục công khai + chi tiết khóa học (US-002, US-003, api-contract §2.1).
 * Route đăng ký ở `routes/api.php` trong nhóm public "không đọc cookie" (M3 —
 * xem `PublicConfigController`), TRỪ `viewerState()` (nhóm `student`).
 */
class CourseController extends Controller
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly CourseViewerStateService $viewerStateService,
    ) {}

    public function index(CourseSearchRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $courses = $this->catalog->search($filters);

        return CourseSummaryResource::collection($courses)
            ->response()
            ->setStatusCode(Response::HTTP_OK)
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function show(Course $course): JsonResponse
    {
        // BR4/BR2 (US-003) — unpublished/xoá mềm → 404. Route model binding
        // (`{course:slug}`) đã tự loại bản ghi soft-deleted; status kiểm ở đây.
        if ($course->status !== CourseStatus::Published) {
            abort(404);
        }

        $course->load(['teachers', 'subjects', 'chapters.lessons']);

        return (new CourseResource($course))
            ->response()
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function viewerState(Request $request, Course $course): JsonResponse
    {
        if ($course->status !== CourseStatus::Published) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        return response()->json(
            $this->viewerStateService->resolve($course, $user)
        );
    }
}
