<?php

namespace App\VideoLab\Http\Controllers;

use App\VideoLab\Http\Requests\CreateVideoRequest;
use App\VideoLab\Models\Video;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * API quản lý kiểu Bunny (`/videolab/library/{lib}/videos`), header AccessKey, chỉ mạng nội bộ (ADR-002 §3).
 */
class ManagementController extends Controller
{
    public function __construct(private readonly VideoLabStorage $storage) {}

    public function store(CreateVideoRequest $request, string $libraryId): JsonResponse
    {
        $this->library($libraryId);

        $ceiling = (int) config('video.max_upload_mb') * 1024 * 1024;
        $max = min($ceiling, (int) $request->integer('max_bytes', $ceiling));

        $video = new Video;
        $video->forceFill([
            'guid' => (string) Str::uuid(),
            'library_id' => $libraryId,
            'title' => (string) $request->string('title'),
            'status' => Video::CREATED,
            'max_bytes' => $max,
            'upload_offset' => 0,
            'upload_expires_at' => now()->addSeconds((int) config('videolab.upload_ttl_seconds')),
            'created_by_ref' => $request->input('created_by_ref'),
        ])->save();

        return response()->json($this->payload($video), 201);
    }

    public function show(Request $request, string $libraryId, string $guid): JsonResponse
    {
        return response()->json($this->payload($this->find($libraryId, $guid)));
    }

    public function destroy(string $libraryId, string $guid): Response
    {
        $video = $this->find($libraryId, $guid);

        $this->storage->deleteAll($video->guid);
        $video->delete();

        return response()->noContent();
    }

    private function library(string $libraryId): void
    {
        abort_unless($libraryId === (string) config('video.library_id'), 404);
    }

    private function find(string $libraryId, string $guid): Video
    {
        $this->library($libraryId);

        return Video::query()->where('library_id', $libraryId)->where('guid', $guid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Video $video): array
    {
        return [
            'guid' => $video->guid,
            'videoLibraryId' => $video->library_id,
            'title' => $video->title,
            'status' => $video->status,
            'length' => $video->length_seconds ?? 0,
            'uploadStarted' => $video->upload_length !== null,
            'uploadOffset' => $video->upload_offset,
            'error' => $video->error,
        ];
    }
}
