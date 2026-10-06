<?php

namespace App\VideoLab\Services;

use App\VideoLab\Exceptions\VideoInvalidUploadException;
use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Support\MagicBytes;
use App\VideoLab\Support\Signature;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tập con TUS 1.0 (Core + Creation) cho VideoLab, kèm các giới hạn ADR-002 §3a.5:
 * chữ ký HMAC + hạn (≤ 6h), `Upload-Length` ≤ max_bytes (413), 1 upload/guid, video đã xong → 403,
 * tên file chỉ để hiển thị. PATCH tuần tự theo khoá hàng (không ghi chồng).
 */
class TusUploadService
{
    public const VERSION = '1.0.0';

    public const OFFSET_CONTENT_TYPE = 'application/offset+octet-stream';

    public function __construct(private readonly VideoLabStorage $storage) {}

    /** Mọi lỗi xác thực trả 403 (không phân biệt guid không tồn tại/chữ ký sai để không dò guid). */
    public function authorize(Request $request, ?string $routeGuid = null): Video
    {
        $signature = (string) $request->header('AuthorizationSignature', '');
        $expire = (string) $request->header('AuthorizationExpire', '');
        $guid = (string) $request->header('VideoId', '');
        $library = (string) $request->header('LibraryId', '');

        if ($signature === '' || ! ctype_digit($expire) || ! Video::isGuid($guid) || $library === '') {
            abort(403, 'Chữ ký upload không hợp lệ.');
        }

        if ($routeGuid !== null && $routeGuid !== $guid) {
            abort(403, 'Chữ ký upload không hợp lệ.');
        }

        if (! hash_equals(Signature::upload($library, (int) $expire, $guid), $signature)) {
            abort(403, 'Chữ ký upload không hợp lệ.');
        }

        if ((int) $expire <= time()) {
            abort(403, 'Chữ ký upload đã hết hạn.');
        }

        $video = Video::query()->where('guid', $guid)->where('library_id', $library)->first();

        if ($video === null || $video->upload_expires_at->isPast()) {
            abort(403, 'Phiên upload không hợp lệ hoặc đã hết hạn.');
        }

        return $video;
    }

    public function requireTusVersion(Request $request): void
    {
        if ($request->header('Tus-Resumable') !== self::VERSION) {
            abort(412, 'Phiên bản TUS không được hỗ trợ.', ['Tus-Version' => self::VERSION, 'Tus-Resumable' => self::VERSION]);
        }
    }

    /** Creation: đặt `Upload-Length`, mở file rỗng. Mỗi guid chỉ 1 phiên. */
    public function create(Request $request): Video
    {
        $this->requireTusVersion($request);
        $video = $this->authorize($request);

        if ($request->headers->has('Upload-Defer-Length')) {
            abort(400, 'Không hỗ trợ Upload-Defer-Length.');
        }

        $length = $request->header('Upload-Length');

        if (! is_string($length) || ! ctype_digit($length) || (int) $length < 1) {
            abort(400, 'Upload-Length không hợp lệ.');
        }

        if ((int) $length > $video->max_bytes) {
            abort(413, 'Dung lượng vượt giới hạn cho phép.');
        }

        return DB::transaction(function () use ($video, $length, $request): Video {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();

            // Đã xong/đang xử lý hoặc đã có phiên upload khác → 403.
            if ($locked->status !== Video::CREATED || $locked->upload_length !== null) {
                abort(403, 'Video này không còn nhận upload.');
            }

            $this->storage->ensureDirs();
            file_put_contents($this->storage->incoming($locked->guid), '');

            $locked->forceFill([
                'upload_length' => (int) $length,
                'upload_offset' => 0,
                'original_name' => $this->displayName((string) $request->header('Upload-Metadata', '')),
            ])->save();

            return $locked;
        });
    }

    /** HEAD: trả offset hiện tại (client dùng để resume). Video đã nhận đủ → offset = length. */
    public function offset(Request $request, string $guid): Video
    {
        $this->requireTusVersion($request);
        $video = $this->authorize($request, $guid);

        if ($video->upload_length === null) {
            abort(404, 'Chưa có phiên upload.');
        }

        return $video;
    }

    /**
     * PATCH: ghi nối tiếp một chunk. Trả Video đã cập nhật.
     */
    public function append(Request $request, string $guid): Video
    {
        $this->requireTusVersion($request);

        if ($request->header('Content-Type') !== self::OFFSET_CONTENT_TYPE) {
            abort(415, 'Content-Type phải là application/offset+octet-stream.');
        }

        $offsetHeader = $request->header('Upload-Offset');

        if (! is_string($offsetHeader) || ! ctype_digit($offsetHeader)) {
            abort(400, 'Upload-Offset không hợp lệ.');
        }

        $video = $this->authorize($request, $guid);

        $result = DB::transaction(function () use ($video, $request, $offsetHeader): Video {
            $locked = Video::query()->whereKey($video->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Video::CREATED) {
                abort(403, 'Video này không còn nhận upload.');
            }

            if ($locked->upload_length === null) {
                abort(404, 'Chưa có phiên upload.');
            }

            if ((int) $offsetHeader !== $locked->upload_offset) {
                abort(409, 'Upload-Offset không khớp.');
            }

            $path = $this->storage->incoming($locked->guid);
            clearstatcache(true, $path);

            if (! is_file($path) || filesize($path) !== $locked->upload_offset) {
                abort(409, 'Trạng thái tệp tải lên không khớp.');
            }

            $remaining = $locked->upload_length - $locked->upload_offset;
            $limit = min($remaining, (int) config('videolab.chunk_max_mb') * 1024 * 1024);
            $declared = $request->header('Content-Length');

            if (is_string($declared) && ctype_digit($declared) && (int) $declared > $limit) {
                abort(413, $remaining < (int) $declared ? 'Dữ liệu vượt Upload-Length.' : 'Chunk quá lớn.');
            }

            $written = $this->writeBody($request, $path, $locked->upload_offset, $limit, $remaining);

            $locked->upload_offset += $written;

            if ($locked->upload_offset === $locked->upload_length) {
                $this->complete($locked, $path);
            } else {
                $locked->save();
            }

            return $locked;
        });

        // Từ chối SAU khi commit để trạng thái upload_failed được lưu (abort trong transaction sẽ rollback).
        if ($result->status === Video::UPLOAD_FAILED) {
            throw new VideoInvalidUploadException('Định dạng tệp không được hỗ trợ. Vui lòng chọn tệp video hợp lệ (mp4, mov, mkv, webm).');
        }

        return $result;
    }

    private function writeBody(Request $request, string $path, int $offset, int $limit, int $remaining): int
    {
        $in = $request->getContent(true);
        $out = fopen($path, 'cb');

        if (! is_resource($in) || $out === false) {
            abort(500, 'Không ghi được dữ liệu tải lên.');
        }

        fseek($out, $offset);
        $written = 0;

        try {
            while (! feof($in)) {
                $buffer = fread($in, 65536);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                $written += strlen($buffer);

                if ($written > $limit) {
                    // Vượt Upload-Length hoặc chunk tối đa: khôi phục file về offset cũ rồi 413.
                    ftruncate($out, $offset);

                    abort(413, $written > $remaining ? 'Dữ liệu vượt Upload-Length.' : 'Chunk quá lớn.');
                }

                if ($this->writeChunk($out, $buffer) !== strlen($buffer)) {
                    // Ghi thiếu (đĩa đầy...): khôi phục về offset cũ để client resume được (không kẹt 409).
                    ftruncate($out, $offset);

                    abort(507, 'Không đủ dung lượng lưu trữ.');
                }
            }
        } finally {
            fclose($out);
        }

        return $written;
    }

    /**
     * @param  resource  $handle
     */
    protected function writeChunk($handle, string $buffer): int|false
    {
        return fwrite($handle, $buffer);
    }

    /** Đủ dung lượng: kiểm magic bytes TRƯỚC mọi ffprobe/ffmpeg, rồi xếp job transcode. */
    private function complete(Video $video, string $incomingPath): void
    {
        if (MagicBytes::detect($incomingPath) === null) {
            @unlink($incomingPath);
            $video->forceFill(['status' => Video::UPLOAD_FAILED, 'error' => 'Định dạng tệp không được hỗ trợ.'])->save();
            SendVideoLabWebhookJob::dispatch($video->guid)->afterCommit();

            return;
        }

        $source = $this->storage->source($video->guid);
        rename($incomingPath, $source);

        $video->forceFill(['status' => Video::UPLOADED, 'source_path' => 'source/'.$video->guid.'.bin'])->save();
        TranscodeVideoJob::dispatch($video->guid)->afterCommit();
    }

    /** `Upload-Metadata: filename <base64>` → tên hiển thị đã làm sạch (không dùng làm đường dẫn). */
    private function displayName(string $metadata): ?string
    {
        foreach (explode(',', $metadata) as $pair) {
            $parts = explode(' ', trim($pair), 2);

            if ($parts[0] === 'filename' && isset($parts[1])) {
                $decoded = base64_decode($parts[1], true);

                if ($decoded !== false) {
                    $name = basename(str_replace('\\', '/', $decoded));
                    $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

                    return $name === '' ? null : mb_substr($name, 0, 255);
                }
            }
        }

        return null;
    }
}
