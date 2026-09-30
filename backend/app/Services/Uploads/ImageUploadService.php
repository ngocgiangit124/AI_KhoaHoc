<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;

/**
 * Xử lý ảnh do người dùng tải lên (thumbnail khóa học — T08, sau này avatar/
 * ảnh câu hỏi) — api-contract §4, S2. Dùng `intervention/image` ^3 (đã duyệt
 * G2) với driver GD (image `gd` có sẵn trên container `php`, không có
 * Imagick — xem `infra/docker/php/Dockerfile`).
 *
 * Không dùng `intervention/image-laravel` (không cần tích hợp Facade/Blade —
 * đúng ghi chú G2 "tránh phụ thuộc thêm").
 *
 * An toàn (S2):
 * - `FormRequest` (`mimes:jpg,jpeg,png,webp` + `mimetypes:...` + `dimensions:...`)
 *   là lớp lọc ĐẦU TIÊN (chặn hầu hết SVG/HTML/polyglot ở tầng validate, trả
 *   422 chuẩn qua `ValidationException`).
 * - Lớp NÀY là phòng thủ thứ 2 (defense in depth): `ImageManager::read()` chỉ
 *   chấp nhận byte thật sự giải mã được thành ảnh raster (GD
 *   `imagecreatefromstring` nội bộ) — file cố tình đổi đuôi/polyglot lọt qua
 *   lớp 1 (hiếm nhưng không loại trừ) sẽ khiến `read()` ném
 *   `DecoderException`; ở đây bắt lại và ném tiếp `ValidationException` (vẫn
 *   422, KHÔNG rơi xuống 500) thay vì để lộ exception nội bộ.
 * - Ảnh luôn được MÃ HOÁ LẠI sang WebP (không giữ nguyên bytes gốc) — việc
 *   giải mã rồi encode lại tự nhiên loại bỏ toàn bộ EXIF/metadata gốc (không
 *   copy thủ công bất kỳ trường metadata nào sang ảnh mới).
 * - Tên file luôn là UUID ngẫu nhiên (không dùng tên gốc người dùng đặt —
 *   tránh path traversal/đoán được tên file).
 */
class ImageUploadService
{
    /**
     * Cạnh dài nhất tối đa sau khi resize (api-contract §4 — "tối đa 1600px").
     */
    private const MAX_DIMENSION = 1600;

    private const WEBP_QUALITY = 82;

    private const DISK = 'uploads';

    /**
     * @return string Tên file đã lưu ({uuid}.webp) — lưu nguyên vào
     *                `courses.thumbnail_path` (chỉ tên file, không phải URL
     *                đầy đủ — frontend tự ghép với `STATIC_URL`).
     */
    public function store(UploadedFile $file): string
    {
        $manager = ImageManager::gd();

        try {
            $image = $manager->read($file->getRealPath());
        } catch (DecoderException $e) {
            // Phòng thủ thứ 2 — xem docblock lớp. Không log nội dung file
            // (không phải PII/secret nhưng vô nghĩa để giữ lại).
            throw ValidationException::withMessages([
                'thumbnail' => 'Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.',
            ]);
        }

        $image->scaleDown(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);

        $encoded = $image->toWebp(quality: self::WEBP_QUALITY);

        $filename = Str::uuid()->toString().'.webp';

        Storage::disk(self::DISK)->put($filename, $encoded->toString());

        return $filename;
    }

    /**
     * Xoá ảnh cũ khi thay ảnh mới (US-009 — thay thumbnail không để lại file
     * mồ côi). An toàn khi gọi với file không tồn tại (`Storage::delete()`
     * không ném lỗi — disk `uploads` có `throw => false`).
     */
    public function delete(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($filename);
    }
}
