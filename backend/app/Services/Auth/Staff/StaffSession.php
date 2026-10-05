<?php

namespace App\Services\Auth\Staff;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Trạng thái phiên quản trị lưu TRONG session (host admin-api, cookie `vv_admin_session`) — T28.
 * Chỉ các nơi đăng nhập/MFA/middleware đọc-ghi các khoá này.
 */
final class StaffSession
{
    /** Unix timestamp lúc qua bước mật khẩu — mốc cho hạn tuyệt đối 12 giờ. */
    public const LOGIN_AT = 'staff_login_at';

    /** Unix timestamp của request gần nhất — mốc cho idle 120 phút. */
    public const LAST_ACTIVITY = 'staff_last_activity';

    /** Đã nhập đúng OTP MFA (hoặc không cần MFA) trong phiên này. */
    public const MFA_PASSED = 'staff_mfa_passed';

    public static function start(Session $session, bool $mfaPassed): void
    {
        $now = now()->getTimestamp();

        $session->put([
            self::LOGIN_AT => $now,
            self::LAST_ACTIVITY => $now,
            self::MFA_PASSED => $mfaPassed,
        ]);
    }

    /**
     * Admin/quản lý trang phải qua OTP email mỗi lần đăng nhập khi bật `FEATURE_STAFF_MFA` (S15, US-016 BR3).
     * Giáo viên không bắt buộc (chỉ có email cảnh báo thiết bị mới).
     */
    public static function requiresMfa(User $user): bool
    {
        return (bool) config('features.staff_mfa') && $user->isStaff();
    }

    public static function mfaPassed(Session $session): bool
    {
        return $session->get(self::MFA_PASSED) === true;
    }

    /** Thời điểm phiên hết hạn tuyệt đối (12 giờ kể từ đăng nhập), null nếu thiếu mốc. */
    public static function absoluteExpiry(Session $session): ?int
    {
        $loginAt = $session->get(self::LOGIN_AT);

        return is_int($loginAt) ? $loginAt + (int) config('auth.staff.absolute_hours') * 3600 : null;
    }
}
