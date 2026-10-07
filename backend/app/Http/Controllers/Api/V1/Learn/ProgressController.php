<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learn\HeartbeatRequest;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(private readonly ProgressService $progress) {}

    public function heartbeat(HeartbeatRequest $request, Lesson $lesson): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->progress->heartbeat(
            $user,
            $lesson,
            $request->integer('position_seconds'),
            $request->integer('watched_delta_seconds'),
        ));
    }

    public function complete(Request $request, Lesson $lesson): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->progress->completeManually($user, $lesson));
    }
}
