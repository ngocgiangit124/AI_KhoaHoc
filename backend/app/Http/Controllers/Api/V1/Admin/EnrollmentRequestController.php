<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Enrollment\DecideEnrollmentRequest;
use App\Http\Requests\Admin\Enrollment\EnrollmentRequestIndexRequest;
use App\Http\Resources\EnrollmentRequestResource;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EnrollmentRequestController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(EnrollmentRequestIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Enrollment::query()
            ->with(['course:id,title,slug', 'user:id,name,email,phone,grade_level'])
            ->where('status', $request->input('status', EnrollmentStatus::PendingApproval->value))
            ->whereHas('course') // bỏ yêu cầu của khóa đã xoá mềm (không trả course null)
            ->orderBy('requested_at')
            ->orderBy('id');

        if ($request->filled('course_id')) {
            $courseId = (int) $request->input('course_id');
            Gate::authorize('viewCourseRequests', [Enrollment::class, $courseId]);
            $query->where('course_id', $courseId);
        } elseif ($user->isTeacher()) {
            // Không lọc: chỉ các khóa mình phụ trách.
            $query->whereIn('course_id', DB::table('course_teacher')->where('user_id', $user->getKey())->select('course_id'));
        }

        return EnrollmentRequestResource::collection(
            $query->paginate((int) $request->input('per_page', 25))->withQueryString()
        );
    }

    public function approve(DecideEnrollmentRequest $request, Enrollment $enrollment): EnrollmentRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $this->enrollments->approve($enrollment, $user, $request->reason());

        return new EnrollmentRequestResource($enrollment->load(['course:id,title,slug', 'user:id,name,email,phone,grade_level']));
    }

    public function reject(DecideEnrollmentRequest $request, Enrollment $enrollment): EnrollmentRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $this->enrollments->reject($enrollment, $user, $request->reason());

        return new EnrollmentRequestResource($enrollment->load(['course:id,title,slug', 'user:id,name,email,phone,grade_level']));
    }
}
