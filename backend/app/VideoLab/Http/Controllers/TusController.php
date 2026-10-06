<?php

namespace App\VideoLab\Http\Controllers;

use App\VideoLab\Models\Video;
use App\VideoLab\Services\TusUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TusController extends Controller
{
    public function __construct(private readonly TusUploadService $tus) {}

    public function options(): Response
    {
        return response('', 204, [
            'Tus-Resumable' => TusUploadService::VERSION,
            'Tus-Version' => TusUploadService::VERSION,
            'Tus-Extension' => 'creation',
            'Tus-Max-Size' => (string) ((int) config('video.max_upload_mb') * 1024 * 1024),
        ]);
    }

    public function create(Request $request): Response
    {
        $video = $this->tus->create($request);

        return response('', 201, $this->headers($video) + [
            // Thân rỗng: không để Symfony mặc định `text/html`.
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Location' => $request->getSchemeAndHttpHost().'/videolab/tus/'.$video->guid,
        ]);
    }

    public function head(Request $request, string $guid): Response
    {
        $video = $this->tus->offset($request, $guid);

        return response('', 200, $this->headers($video) + ['Upload-Length' => (string) $video->upload_length, 'Cache-Control' => 'no-store']);
    }

    public function patch(Request $request, string $guid): Response
    {
        $video = $this->tus->append($request, $guid);

        return response('', 204, $this->headers($video));
    }

    /**
     * @return array<string, string>
     */
    private function headers(Video $video): array
    {
        return [
            'Tus-Resumable' => TusUploadService::VERSION,
            // Đã nhận đủ (status ≥ 1): luôn báo offset = length để client không gửi lại.
            'Upload-Offset' => (string) ($video->status >= Video::UPLOADED ? $video->upload_length : $video->upload_offset),
        ];
    }
}
