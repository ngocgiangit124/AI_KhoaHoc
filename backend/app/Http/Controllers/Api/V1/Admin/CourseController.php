<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Course\CourseIndexRequest;
use App\Http\Requests\Admin\Course\ManualOrderRequest;
use App\Http\Requests\Admin\Course\StoreCourseRequest;
use App\Http\Requests\Admin\Course\UpdateCourseRequest;
use App\Http\Resources\CourseListResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CourseController extends Controller
{
    /** Không chọn `description` (LONGTEXT) cho danh sách. */
    private const LIST_COLUMNS = [
        'courses.id', 'courses.title', 'courses.slug', 'courses.short_description', 'courses.grade_level',
        'courses.price', 'courses.thumbnail_path', 'courses.status', 'courses.published_at', 'courses.manual_order',
        'courses.enrollments_count', 'courses.created_by', 'courses.created_at', 'courses.updated_at',
    ];

    public function __construct(private readonly CourseService $courses) {}

    public function index(CourseIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Course::query()
            ->select(self::LIST_COLUMNS)
            ->visibleTo($user)
            ->with(['subjects:id,name,slug', 'teachers:id,name'])
            ->orderByDesc('courses.created_at')->orderByDesc('courses.id');

        if ($request->filled('q')) {
            // search_text đã bỏ dấu + chữ thường: chuẩn hoá từ khoá y như vậy rồi escape LIKE (S24).
            $term = Course::buildSearchText($request->string('q')->toString(), null);
            $query->where('courses.search_text', 'like', Like::contains($term));
        }

        if ($request->filled('status')) {
            $query->where('courses.status', $request->string('status')->toString());
        }

        if ($request->filled('grade_level')) {
            $query->where('courses.grade_level', $request->integer('grade_level'));
        }

        if ($request->filled('subject_id')) {
            $query->whereHas('subjects', fn ($q) => $q->whereKey($request->integer('subject_id')));
        }

        if ($request->filled('teacher_id') && $user->isStaff()) {
            $query->whereHas('teachers', fn ($q) => $q->whereKey($request->integer('teacher_id')));
        }

        return CourseListResource::collection(
            $query->paginate((int) $request->input('per_page', 25))->withQueryString(),
        );
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $course = $this->courses->create(
            $request->safe()->except(['thumbnail', 'teacher_ids']),
            $request->file('thumbnail'),
            $request->teacherIds(),
            $user,
        );

        return $this->respond($course, $request)->setStatusCode(201);
    }

    public function show(Request $request, Course $course): CourseResource
    {
        Gate::authorize('view', $course);

        return $this->detail($course);
    }

    public function update(UpdateCourseRequest $request, Course $course): CourseResource
    {
        /** @var User $user */
        $user = $request->user();

        $course = $this->courses->update(
            $course,
            $request->safe()->except(['thumbnail']),
            $request->file('thumbnail'),
            $user,
        );

        return $this->detail($course);
    }

    public function destroy(Course $course): Response
    {
        Gate::authorize('delete', $course);

        $this->courses->delete($course);

        return response()->noContent();
    }

    public function updateManualOrder(ManualOrderRequest $request, Course $course): CourseResource
    {
        $order = $request->validated('manual_order');

        return $this->detail($this->courses->setManualOrder($course, $order === null ? null : (int) $order));
    }

    private function respond(Course $course, Request $request): JsonResponse
    {
        return $this->detail($course)->response($request);
    }

    private function detail(Course $course): CourseResource
    {
        return new CourseResource($this->courses->loadDetail($course));
    }
}
