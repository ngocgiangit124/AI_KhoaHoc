<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learn\MyCoursesRequest;
use App\Models\Course;
use App\Models\User;
use App\Services\Learning\LessonAccessService;
use App\Services\Learning\MyCoursesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MyCourseController extends Controller
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly MyCoursesService $service,
    ) {}

    public function index(MyCoursesRequest $request): JsonResponse
    {
        $startedAt = microtime(true);
        /** @var User $user */
        $user = $request->user();

        $result = $this->service->list(
            $user,
            $request->integer('page', 1),
            $request->filled('per_page') ? $request->integer('per_page') : (int) config('learning.my_courses.per_page'),
        );
        $paginator = $result['paginator'];

        $response = response()->json([
            'data' => $result['items'],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'pending' => $result['pending'],
            'rejected' => $result['rejected'],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        $this->logTiming('me.courses', $startedAt, $user, ['count' => count($result['items']), 'total' => $paginator->total()]);

        return $response;
    }

    public function progress(Request $request, Course $course): JsonResponse
    {
        $startedAt = microtime(true);
        /** @var User $user */
        $user = $request->user();
        $this->access->assertCanLearnCourse($user, $course);

        $response = response()->json($this->service->progress($user, $course));
        $response->headers->set('Cache-Control', 'no-store, private');

        $this->logTiming('me.courses.progress', $startedAt, $user, ['course_id' => $course->id]);

        return $response;
    }

    /**
     * Log thời gian xử lý (channel `learning`) để theo dõi p95; vượt ngưỡng thì warning.
     *
     * @param  array<string, mixed>  $context
     */
    private function logTiming(string $message, float $startedAt, User $user, array $context): void
    {
        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        $slow = $ms > (int) config('learning.my_courses.slow_ms');

        Log::channel('learning')->log($slow ? 'warning' : 'info', $message, $context + ['user_id' => $user->id, 'duration_ms' => $ms, 'slow' => $slow]);
    }
}
