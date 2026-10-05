<?php

namespace App\Support;

/**
 * Dựng URL tuyệt đối cho tệp tĩnh (ảnh khóa học, avatar) từ tên tệp đã lưu — phục vụ từ tên miền tĩnh
 * riêng không chia sẻ cookie (`STATIC_URL`, api-contract §4).
 *
 * Phòng thủ: trả null nếu path không phải đường dẫn tương đối sạch (có `..`, `://`, mở đầu `//`, `\`,
 * ký tự điều khiển/khoảng trắng) hoặc `STATIC_URL` chưa cấu hình.
 */
final class StaticUrl
{
    public static function to(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $base = rtrim((string) config('app.static_url'), '/');
        if ($base === '') {
            return null;
        }

        if (
            str_contains($path, '..')
            || str_contains($path, '://')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
        ) {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
