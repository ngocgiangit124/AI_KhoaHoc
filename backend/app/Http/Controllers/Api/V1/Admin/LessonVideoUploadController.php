<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\VideoUploadRequest;
use App\Http\Resources\LessonVideoResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Video\VideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LessonVideoUploadController extends Controller
{
    public function __construct(private readonly VideoUploadService $uploads) {}

    public function store(VideoUploadRequest $request, Course $course, Lesson $lesson): JsonResponse
    {
        $result = $this->uploads->createUpload(
            $request->user(),
            $course,
            $lesson,
            (string) $request->validated('filename'),
            (int) $request->validated('size'),
        );

        $target = $result['target'];

        return response()->json([
            'video_asset_id' => $result['asset']->getKey(),
            'status' => $result['asset']->status->value,
            'upload' => [
                'protocol' => $target->protocol,
                'tus_endpoint' => $target->endpoint,
                'headers' => $target->headers,
                'expires_at' => $target->expiresAt->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Course $course, Lesson $lesson): LessonVideoResource
    {
        Gate::authorize('manageContent', $course);

        return new LessonVideoResource($lesson->load('videoAsset'));
    }
}
