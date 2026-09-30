<?php

use App\Services\Uploads\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * US-009 / api-contract §4 (S2). Test bắt buộc theo tasks.md T08: SVG/HTML/
 * polyglot → 422 (ở tầng `FormRequest`, qua route thật — xem
 * `CourseAdminTest`); EXIF bị xoá (ở đây, đơn vị nhỏ nhất chứng minh
 * `ImageUploadService` tự nó xoá EXIF, không phụ thuộc route).
 */
function buildJpegWithExifCanary(string $canary): string
{
    ob_start();
    $image = imagecreatetruecolor(20, 20);
    imagejpeg($image);
    $jpeg = ob_get_clean();
    imagedestroy($image);

    $makeValue = $canary."\0";
    $makeLen = strlen($makeValue);

    // TIFF header little-endian + 1 IFD entry (tag Make = 0x010F, ASCII) —
    // đủ để `exif_read_data()` đọc được (kiểm ở test "chứa canary trước khi
    // xử lý") và đủ để GD giải mã bình thường (JPEG hợp lệ có thêm 1 đoạn
    // APP1/EXIF ngay sau SOI).
    $tiff = 'II'.pack('v', 42).pack('V', 8);
    $ifdEntryCount = 1;
    $tagId = 0x010F;
    $tagType = 2;
    $dataOffsetInTiff = 8 + 2 + 12 * $ifdEntryCount + 4;
    $ifd = pack('v', $ifdEntryCount)
        .pack('v', $tagId).pack('v', $tagType).pack('V', $makeLen).pack('V', $dataOffsetInTiff)
        .pack('V', 0);

    $exifPayload = $tiff.$ifd.$makeValue;
    $exifBlock = "Exif\0\0".$exifPayload;
    $app1 = "\xFF\xE1".pack('n', strlen($exifBlock) + 2).$exifBlock;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

beforeEach(function () {
    Storage::fake('uploads');
});

test('anh hop le duoc luu thanh webp voi ten uuid ngau nhien', function () {
    $file = UploadedFile::fake()->image('thumb.jpg', 800, 600);

    $filename = app(ImageUploadService::class)->store($file);

    expect($filename)->toMatch('/^[0-9a-f-]{36}\.webp$/');
    Storage::disk('uploads')->assertExists($filename);

    $contents = Storage::disk('uploads')->get($filename);
    expect(substr($contents, 0, 4))->toBe('RIFF'); // WebP = container RIFF.
});

test('exif bi xoa hoan toan sau khi ma hoa lai sang webp', function () {
    $canary = 'VVTESTCANARY'.uniqid();
    $bytes = buildJpegWithExifCanary($canary);

    // Xác nhận file gốc THẬT SỰ có EXIF (nếu không, test này vô nghĩa).
    $tmpPath = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($tmpPath, $bytes);
    $exif = @exif_read_data($tmpPath);
    expect($exif)->not->toBeFalse();
    expect($exif['Make'] ?? null)->toBe($canary);

    $file = new UploadedFile($tmpPath, 'with-exif.jpg', 'image/jpeg', null, true);

    $filename = app(ImageUploadService::class)->store($file);

    $output = Storage::disk('uploads')->get($filename);
    expect($output)->not->toContain($canary);

    @unlink($tmpPath);
});

test('anh cu bi xoa khi thay anh moi', function () {
    $service = app(ImageUploadService::class);

    $old = $service->store(UploadedFile::fake()->image('old.jpg', 400, 400));
    Storage::disk('uploads')->assertExists($old);

    $service->delete($old);
    Storage::disk('uploads')->assertMissing($old);
});

test('xoa ten file null khong lam gi, khong loi', function () {
    app(ImageUploadService::class)->delete(null);
    app(ImageUploadService::class)->delete('');

    expect(true)->toBeTrue();
});
