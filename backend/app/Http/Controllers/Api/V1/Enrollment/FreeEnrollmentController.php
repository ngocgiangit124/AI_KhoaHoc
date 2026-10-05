<?php

namespace App\Http\Controllers\Api\V1\Enrollment;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyEnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FreeEnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('requestFree', [Enrollment::class, $course]);

        /** @var User $user */
        $user = $request->user();

        $enrollment = $this->enrollments->requestFree($user, $course);

        return (new MyEnrollmentResource($enrollment))->response()->setStatusCode(201);
    }
}
