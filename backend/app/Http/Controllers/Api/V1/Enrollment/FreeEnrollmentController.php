<?php

namespace App\Http\Controllers\Api\V1\Enrollment;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Enrollment\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-012 — đăng ký khóa học miễn phí. Route ở `routes/api.php`, nhóm
 * `student` + `account.verified` + `parent.consent` (api-contract §2.4).
 */
class FreeEnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function store(Request $request, Course $course): JsonResponse
    {
        // BR1 (US-012)/US-003 — unpublished/xoá mềm → 404, giống
        // `CourseController@show`/`viewerState()`: không lộ sự tồn tại của
        // khóa chưa publish qua 403. Route model binding tự loại bản ghi đã
        // xoá mềm; chỉ còn cần kiểm `status` ở đây.
        if ($course->status !== CourseStatus::Published) {
            abort(404);
        }

        // Gate chọn Policy theo class của phần tử đầu → truyền Enrollment::class
        // để dùng EnrollmentPolicy (nếu chỉ truyền $course sẽ tìm CoursePolicy).
        $this->authorize('requestFree', [Enrollment::class, $course]);

        /** @var User $student */
        $student = $request->user();

        $enrollment = $this->enrollments->requestFree($course, $student);

        return EnrollmentResource::make($enrollment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
