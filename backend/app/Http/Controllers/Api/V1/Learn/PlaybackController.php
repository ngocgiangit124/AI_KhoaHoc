<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\PlaybackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlaybackController extends Controller
{
    public function __construct(private readonly PlaybackService $playback) {}

    /** Học sinh: chủ khóa (ràng IP) hoặc bài preview. */
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->playback->forStudent($user, $lesson, $request->ip(), $request->userAgent()));
    }

    /** Công khai, không đăng nhập: chỉ bài preview. Mọi lý do từ chối đều là 404 (không lộ bài nào tồn tại). */
    public function preview(Request $request, Lesson $lesson): JsonResponse
    {
        return $this->respond($this->playback->forPublicPreview($lesson, $request->ip(), $request->userAgent()));
    }

    /** Quản trị: staff hoặc giáo viên được gán xem thử; `{lesson}` đã bị ràng thuộc `{course}` (scopeBindings). */
    public function admin(Request $request, Course $course, Lesson $lesson): JsonResponse
    {
        Gate::authorize('watch', $lesson);

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->playback->forStaff($user, $lesson, $request->ip(), $request->userAgent()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
