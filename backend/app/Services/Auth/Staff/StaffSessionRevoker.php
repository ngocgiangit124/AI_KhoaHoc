<?php

namespace App\Services\Auth\Staff;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Huỷ MỌI phiên quản trị đang sống của một tài khoản (T33) mà không cần liệt kê session (Redis không tra theo
 * user). Mỗi tài khoản có một "phiên bản huỷ phiên" trong cache; phiên ghi lại phiên bản lúc đăng nhập
 * (`StaffSession::start`) và `staff.idle` từ chối phiên có phiên bản cũ. Dùng khi khoá, đổi vai trò, đặt lại
 * mật khẩu để phiên cũ không "sống lại" sau khi mở khoá.
 */
final class StaffSessionRevoker
{
    public static function version(int|string $userId): int
    {
        return (int) Cache::get(self::key($userId), 0);
    }

    /**
     * Không ném lỗi: được gọi SAU khi thao tác đã commit; cache lỗi chỉ ghi log (khoá vẫn có hiệu lực nhờ
     * `account.active`, đổi vai trò nhờ middleware `role`, đặt lại mật khẩu nhờ AuthenticateSession).
     */
    public static function revokeAll(int|string $userId): void
    {
        try {
            $key = self::key($userId);

            // TTL dài hơn hạn tuyệt đối của phiên: hết TTL thì mọi phiên cũ cũng đã hết hạn.
            Cache::add($key, 0, now()->addHours((int) config('auth.staff.absolute_hours') + 1));
            Cache::increment($key);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function key(int|string $userId): string
    {
        return 'staff-session-version:'.$userId;
    }
}
