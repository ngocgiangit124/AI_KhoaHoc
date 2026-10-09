<?php

namespace App\Services\Orders;

use App\Models\User;
use App\Services\Privacy\AccountAnonymizer;
use App\Support\Mask;

/**
 * Khối `student` của đơn cho quản trị (api-contract §2.5.1). Danh sách LUÔN che email/SĐT (S14); chi tiết đầy đủ và đã được ghi
 * audit `order.view_pii`. Tài khoản đã ẩn danh: `is_deleted = true`, tên cố định, email/SĐT `null` (không bao giờ 500). Không có
 * thông tin phụ huynh ở đây (cột phụ huynh thậm chí không được nạp).
 */
final class PiiMasker
{
    /** Cột cần nạp từ `users` để dựng khối này. */
    public const USER_COLUMNS = ['id', 'name', 'email', 'phone', 'email_verified_at', 'phone_verified_at', 'status', 'anonymized_at'];

    /** @return array<string, mixed> */
    public static function masked(?User $student, int $fallbackId): array
    {
        if ($student === null || $student->anonymized_at !== null) {
            return ['id' => $student?->getKey() ?? $fallbackId, 'name' => AccountAnonymizer::DELETED_NAME, 'email_masked' => null, 'phone_masked' => null, 'is_deleted' => true];
        }

        return [
            'id' => $student->getKey(),
            'name' => $student->name,
            'email_masked' => Mask::email($student->email),
            'phone_masked' => Mask::phone($student->phone),
            'is_deleted' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function full(?User $student, int $fallbackId): array
    {
        $deleted = $student === null || $student->anonymized_at !== null;

        return [
            'id' => $student?->getKey() ?? $fallbackId,
            'name' => $deleted ? AccountAnonymizer::DELETED_NAME : $student->name,
            'email' => $deleted ? null : $student->email,
            'email_verified' => ! $deleted && $student->email_verified_at !== null,
            'phone' => $deleted ? null : $student->phone,
            'phone_verified' => ! $deleted && $student->phone_verified_at !== null,
            'account_status' => $student?->status->value ?? 'active',
            'is_deleted' => $deleted,
        ];
    }
}
