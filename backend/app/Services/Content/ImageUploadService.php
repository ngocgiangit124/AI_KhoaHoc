<?php

namespace App\Services\Content;

use App\Support\StaticUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Decoders\BinaryImageDecoder;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Lưu ảnh người dùng tải lên an toàn (S2, api-contract §4). Không bao giờ lưu file gốc: đọc lại bằng GD và mã hoá
 * lại thành WebP (≤ 1600px) nên EXIF/metadata, payload nhúng và polyglot bị loại. Tên file là UUID do server sinh.
 * Việc kiểm mimes/mimetypes/kích thước nằm ở FormRequest (`rules()`), service này là lớp phòng thủ thứ hai.
 */
class ImageUploadService
{
    public const DISK = 'uploads';

    private const MAX_EDGE = 1600;

    private const QUALITY = 82;

    /**
     * `$squareEdge` (US-020, ảnh đại diện giáo viên): cắt GIỮA thành hình vuông (cạnh = min(rộng, cao)) rồi thu nhỏ còn
     * ≤ `$squareEdge`, KHÔNG phóng to. Không truyền thì giữ hành vi cũ của thumbnail khóa học (≤ 1600px, giữ tỷ lệ).
     *
     * @return string tên file đã lưu (`{uuid}.webp`) trên disk `uploads`
     *
     * @throws ValidationException khi file không phải ảnh giải mã được
     */
    public function storeWebp(UploadedFile $file, string $field = 'thumbnail', ?int $squareEdge = null): string
    {
        try {
            $binary = (string) file_get_contents($file->getRealPath());
            // Chỉ giải mã dữ liệu nhị phân: không để Intervention coi chuỗi là đường dẫn file hoặc data-URI/base64 (I3).
            $image = (new ImageManager(new Driver))->read($binary, BinaryImageDecoder::class);

            if ($squareEdge !== null) {
                $side = min($image->width(), $image->height());
                $image = $image->crop($side, $side, position: 'center')->scaleDown($squareEdge, $squareEdge);
            } else {
                $image = $image->scaleDown(self::MAX_EDGE, self::MAX_EDGE);
            }

            $encoded = $image->toWebp(self::QUALITY);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Ảnh không hợp lệ hoặc không đọc được.']);
        }

        $name = (string) Str::uuid().'.webp';

        if (! Storage::disk(self::DISK)->put($name, (string) $encoded)) {
            throw new \RuntimeException('Không ghi được ảnh lên disk uploads.');
        }

        return $name;
    }

    public function delete(?string $name): void
    {
        // Chỉ xoá tên do chính service sinh (`{uuid}.webp`), không bao giờ theo đường dẫn tuỳ ý.
        if ($name === null || preg_match('/^[0-9a-f-]{36}\.webp$/', $name) !== 1) {
            return;
        }

        try {
            if (Storage::disk(self::DISK)->exists($name) && ! Storage::disk(self::DISK)->delete($name)) {
                Log::warning('Không xoá được ảnh trên disk uploads (images:prune-orphans sẽ dọn lại).', ['file' => $name]);
            }
        } catch (Throwable $e) {
            Log::warning('Lỗi khi xoá ảnh trên disk uploads (images:prune-orphans sẽ dọn lại).', ['file' => $name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sao chép ảnh sang tên UUID mới để URL cũ hết hiệu lực (rút đồng ý công khai, US-020 M1). Người gọi cập nhật
     * tham chiếu rồi xoá file cũ SAU khi commit.
     *
     * @return string tên file mới
     *
     * @throws \RuntimeException khi file gốc không tồn tại hoặc không sao chép được
     */
    public function rotate(string $name): string
    {
        if (preg_match('/^[0-9a-f-]{36}\.webp$/', $name) !== 1) {
            throw new \RuntimeException('Tên ảnh không hợp lệ.');
        }

        $new = (string) Str::uuid().'.webp';

        if (! Storage::disk(self::DISK)->copy($name, $new)) {
            throw new \RuntimeException('Không sao chép được ảnh trên disk uploads.');
        }

        return $new;
    }

    public function url(?string $name): ?string
    {
        return StaticUrl::to($name);
    }
}
