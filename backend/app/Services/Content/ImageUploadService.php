<?php

namespace App\Services\Content;

use App\Support\StaticUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
     * @return string tên file đã lưu (`{uuid}.webp`) trên disk `uploads`
     *
     * @throws ValidationException khi file không phải ảnh giải mã được
     */
    public function storeWebp(UploadedFile $file, string $field = 'thumbnail'): string
    {
        try {
            $binary = (string) file_get_contents($file->getRealPath());
            $encoded = (new ImageManager(new Driver))
                ->read($binary)
                ->scaleDown(self::MAX_EDGE, self::MAX_EDGE)
                ->toWebp(self::QUALITY);
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
        if ($name !== null && preg_match('/^[0-9a-f-]{36}\.webp$/', $name) === 1) {
            Storage::disk(self::DISK)->delete($name);
        }
    }

    public function url(?string $name): ?string
    {
        return StaticUrl::to($name);
    }
}
