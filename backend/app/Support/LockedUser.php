<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\User;

/**
 * Khoá dòng `users` FOR UPDATE rồi từ chối tài khoản đã ẩn danh hoá (T34, security S1). Mọi ghi vào `users` hoặc bảng con
 * của học sinh dựa trên model nạp TRƯỚC khi pha A xoá tài khoản commit phải đi qua đây, nếu không sẽ ghi PII mới (email,
 * mật khẩu, mã OTP, đồng ý...) vào dòng đã ẩn danh. Gọi TRONG transaction.
 */
final class LockedUser
{
    /**
     * @throws DomainException SESSION_REVOKED 401 khi tài khoản đã ẩn danh
     */
    public static function lockActive(int|string $id): User
    {
        /** @var User $locked */
        $locked = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($locked->anonymized_at !== null) {
            throw new DomainException('SESSION_REVOKED', 'Tài khoản đã được xoá.', 401);
        }

        return $locked;
    }
}
