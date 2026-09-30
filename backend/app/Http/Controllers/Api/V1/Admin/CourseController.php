<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCourseIndexRequest;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseManualOrderRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Http\Resources\Admin\CourseResource;
use App\Models\Course;
use App\Services\Courses\CourseService;
use App\Support\Like;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * `/admin/courses[/{course}]` (US-009, api-contract §2.5). Route đăng ký ở
 * `routes/admin.php`, nhóm middleware `staff` (host admin-api).
 */
class CourseController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    /**
     * Phân quyền: `$this->authorize()` (không phải route middleware `can:`)
     * — theo đúng mẫu `SubjectController::index` (T06): route GET không có
     * `FormRequest` nào cần "authorize trước validate", nên gọi trực tiếp ở
     * đây là đủ.
     */
    public function index(AdminCourseIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Course::class);

        $query = Course::query()
            ->with(['subjects', 'teachers'])
            ->visibleTo($request->user());

        if ($grade = $request->validated('grade')) {
            $query->where('grade_level', $grade);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($subjectId = $request->validated('subject_id')) {
            $query->whereHas('subjects', fn ($q) => $q->whereKey($subjectId));
        }

        // S24 — cùng cách làm với `CourseCatalogService` (T10): so khớp trên
        // `search_text` (đã bỏ dấu, viết thường) qua `Like::contains()`.
        if ($q = $request->validated('q')) {
            $normalized = Str::lower(Str::ascii($q));
            $query->where('search_text', 'like', Like::contains($normalized));
        }

        $perPage = (int) $request->validated('per_page', 25);

        return CourseResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->withQueryString()
        );
    }

    /**
     * Phân quyền chạy trước ở middleware `can:create,...` của route.
     */
    public function store(StoreCourseRequest $request): CourseResource
    {
        $course = $this->courses->create($request->validated(), $request->user());

        return CourseResource::make($course->load(['subjects', 'teachers']));
    }

    /**
     * Phân quyền chạy trước ở middleware `can:view,course` của route.
     */
    public function show(Course $course): CourseResource
    {
        return CourseResource::make($course->load(['subjects', 'teachers']));
    }

    /**
     * Phân quyền chạy trước ở middleware `can:update,course` của route.
     */
    public function update(UpdateCourseRequest $request, Course $course): CourseResource
    {
        $course = $this->courses->update($course, $request->validated(), $request->user());

        return CourseResource::make($course->load(['subjects', 'teachers']));
    }

    /**
     * Phân quyền chạy trước ở middleware `can:delete,course` của route.
     * AC4 — có enrollment → 409 (`DomainException`, xem `CourseService::delete()`).
     */
    public function destroy(Course $course): Response
    {
        $this->courses->delete($course);

        return response()->noContent();
    }

    /**
     * Phân quyền chạy trước ở middleware `can:updateManualOrder,course` của route.
     */
    public function updateManualOrder(UpdateCourseManualOrderRequest $request, Course $course): CourseResource
    {
        $course = $this->courses->updateManualOrder($course, $request->validated('manual_order'));

        return CourseResource::make($course->load(['subjects', 'teachers']));
    }
}
