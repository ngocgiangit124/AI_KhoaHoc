<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnrollmentRequestIndexRequest;
use App\Http\Requests\Admin\RejectEnrollmentRequest;
use App\Http\Resources\Admin\EnrollmentRequestResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /admin/enrollment-requests`, `POST .../approve · /reject` (US-012,
 * api-contract §2.5). Route đăng ký ở `routes/admin.php`, nhóm `staff` (host
 * admin-api).
 */
class EnrollmentRequestController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(EnrollmentRequestIndexRequest $request): AnonymousResourceCollection
    {
        $courseId = $request->validated('course_id');
        $course = $courseId !== null ? Course::findOrFail($courseId) : null;

        $this->authorize('viewAnyRequests', [Enrollment::class, $course]);

        $query = Enrollment::query()
            ->with(['user', 'course'])
            ->where('status', EnrollmentStatus::PendingApproval->value)
            // US-012 AC7 — "sắp xếp theo thời gian gửi sớm nhất trước".
            ->orderBy('requested_at');

        /** @var User $user */
        $user = $request->user();

        if ($course !== null) {
            $query->where('course_id', $course->getKey());
        } elseif ($user->isTeacher()) {
            // BR4 — GV không lọc course_id: chỉ thấy yêu cầu của khóa mình
            // phụ trách (Admin/Quản lý trang thấy toàn bộ — không thêm điều
            // kiện gì).
            $query->whereHas('course.teachers', fn ($q) => $q->whereKey($user->getKey()));
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 50));

        return EnrollmentRequestResource::collection($query->paginate($perPage));
    }

    /**
     * Phân quyền chạy TRƯỚC ở middleware `can:decide,enrollment`
     * (routes/admin.php, mẫu T06) — không gọi `authorize()` trùng lặp ở đây.
     */
    public function approve(Request $request, Enrollment $enrollment): EnrollmentRequestResource
    {
        /** @var User $approver */
        $approver = $request->user();

        $enrollment = $this->enrollments->approve($enrollment, $approver);

        return EnrollmentRequestResource::make($enrollment->load(['user', 'course']));
    }

    /**
     * Phân quyền chạy TRƯỚC ở middleware `can:decide,enrollment`.
     */
    public function reject(RejectEnrollmentRequest $request, Enrollment $enrollment): EnrollmentRequestResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $enrollment = $this->enrollments->reject($enrollment, $actor, $request->validated('reason'));

        return EnrollmentRequestResource::make($enrollment->load(['user', 'course']));
    }
}
