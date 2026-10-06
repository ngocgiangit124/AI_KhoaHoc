<?php

namespace App\VideoLab\Http\Controllers;

use App\VideoLab\Support\Signature;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phát HLS có token (ADR-002 §3, §3a.4). Route đã ràng buộc `guid`/`path` bằng regex; ở đây kiểm token (HMAC theo
 * thư mục `/{guid}/`, tuỳ chọn ràng IP), hạn, trạng thái video và `realpath` nằm trong hls/{guid}/.
 */
class CdnController extends Controller
{
    public function __construct(private readonly VideoLabStorage $storage) {}

    public function show(Request $request, string $token, string $expires, string $guid, string $path): Response
    {
        $expiresAt = (int) $expires;

        abort_unless($expiresAt >= time() && $this->tokenValid($request, $token, $expiresAt, $guid), 403);

        // Không truy vấn DB mỗi segment: `hls/{guid}/` chỉ tồn tại sau khi TranscodeService đổi tên thư mục work khi
        // video đã xong (xoá video xoá luôn thư mục) và token hợp lệ đã được kiểm ở trên.
        $file = $this->storage->resolveHlsFile($guid, $path);
        abort_if($file === null, 404);

        $isPlaylist = str_ends_with($path, '.m3u8');
        $headers = [
            'Content-Type' => $isPlaylist ? 'application/vnd.apple.mpegurl' : 'video/mp2t',
            // Token gắn người dùng/IP: không cho cache dùng chung; hết hạn token thì cache cũng vô nghĩa.
            'Cache-Control' => 'private, max-age='.max(0, min(300, $expiresAt - time())),
        ];

        if (config('videolab.accel_redirect')) {
            return response('', 200, $headers + ['X-Accel-Redirect' => rtrim((string) config('videolab.accel_prefix'), '/').'/'.$guid.'/'.$path]);
        }

        // BinaryFileResponse mặc định `public`: token gắn người dùng/IP nên ép `private`.
        return response()->file($file, $headers)->setPrivate();
    }

    private function tokenValid(Request $request, string $token, int $expires, string $guid): bool
    {
        if (hash_equals(Signature::playback($guid, $expires), $token)) {
            return true;
        }

        $ip = $request->ip();

        return $ip !== null && hash_equals(Signature::playback($guid, $expires, $ip), $token);
    }
}
