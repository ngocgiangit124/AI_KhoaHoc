<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // BE-backlog-1 (FA5): cờ để màn soạn quiz ẩn ô thời gian khi FEATURE_QUIZ_TIME_LIMIT tắt (cùng nguồn với /config).
        return response()->json([
            ...(new StaffUserResource($request->user()))->resolve($request),
            'quiz_time_limit_enabled' => (bool) config('features.quiz_time_limit'),
        ]);
    }
}
