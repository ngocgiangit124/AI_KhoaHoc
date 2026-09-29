<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chuẩn hoá `device_id`/`X-Device-Id` (ADR-003) — UUID do frontend sinh 1 lần
 * (lưu `localStorage`), gửi trong body đăng nhập/đăng ký VÀ header
 * `X-Device-Id` ở mọi request. CHỈ dùng để CHỌN THÔNG ĐIỆP (phân biệt
 * `SESSION_EXPIRED` — cùng thiết bị — với `SESSION_REPLACED` — thiết bị
 * khác), KHÔNG BAO GIỜ dùng để cấp quyền. Sai định dạng hoặc thiếu → coi như
 * không có (trả `null`), không bao giờ làm request thất bại vì lý do này.
 *
 * Quyết định triển khai (ghi rõ cho reviewer): `X-Device-Id` là nguồn CHUẨN
 * (đúng theo api-contract §1.2 — gửi ở MỌI request, kể cả chính request đăng
 * nhập), body `device_id` (đã có sẵn ở `LoginRequest`/`RegisterRequest` từ
 * T03, chưa dùng) chỉ là dự phòng nếu FE nào đó chưa gắn header. Middleware
 * `EnforceSingleStudentSession` sau này CHỈ có header để so sánh (không có
 * body), nên dùng cùng 1 nguồn (header trước) giữa lúc ghi (`bind()`) và lúc
 * so sánh giúp không bao giờ lệch nhau.
 */
final class DeviceId
{
    public static function fromRequest(Request $request): ?string
    {
        return self::normalize($request->header('X-Device-Id'))
            ?? self::normalize($request->input('device_id'));
    }

    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '' || strlen($trimmed) > 64 || ! Str::isUuid($trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
