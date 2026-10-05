<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;

/**
 * Một thiết bị / một phiên cho Học Sinh (ADR-003, US-014).
 *
 * CHỈ lớp này được ghi `users.current_session_id`, `users.current_device_id` và tombstone.
 * Không có nhánh "nhận nuôi": giá trị khác id hiện tại (kể cả `logged_out`/NULL) luôn bị từ chối.
 */
class StudentSessionService
{
    public const LOGGED_OUT = 'logged_out';

    public const REASON_REPLACED = 'replaced';

    public const REASON_PASSWORD_CHANGED = 'password_changed';

    public const REASON_LOCKED = 'locked';

    /**
     * `X-Device-Id`/`device_id` chỉ dùng để chọn thông điệp, không cấp quyền: sai định dạng
     * (không phải UUID, > 64 ký tự, không phải chuỗi) thì coi như không có.
     */
    public static function sanitizeDeviceId(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 64 || ! Str::isUuid($value)) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * Ưu tiên header (có ở mọi request), rồi tới body `device_id` (lúc đăng nhập/đăng ký).
     */
    public static function deviceIdFromRequest(Request $request, bool $allowBody = false): ?string
    {
        $fromHeader = self::sanitizeDeviceId($request->header('X-Device-Id'));

        if ($fromHeader !== null || ! $allowBody) {
            return $fromHeader;
        }

        return self::sanitizeDeviceId($request->input('device_id'));
    }

    public static function tombstoneKey(string $sessionId): string
    {
        return 'session_replaced:'.hash('sha256', $sessionId);
    }

    /**
     * @return array{reason: string, new_device_id: ?string, at: string}|null
     */
    public static function tombstone(string $sessionId): ?array
    {
        $value = Cache::get(self::tombstoneKey($sessionId));

        return is_array($value) && isset($value['reason']) ? $value : null;
    }

    /**
     * Gọi NGAY SAU `Auth::login()` + `session()->regenerate()`. Lỗi ghi DB → đăng xuất phiên mới
     * và ném lại lỗi (phiên cũ vẫn là phiên duy nhất — không bao giờ có 2 phiên hợp lệ).
     */
    public function bind(User $user, Request $request): void
    {
        if ($user->role !== UserRole::Student) {
            return;
        }

        $newId = $request->session()->getId();
        // Body `device_id` chỉ có ý nghĩa ở login/register (nơi gọi bind()).
        $deviceId = self::deviceIdFromRequest($request, allowBody: true);
        $now = now();

        try {
            $old = DB::transaction(function () use ($user, $newId, $deviceId, $now): ?string {
                $row = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $old = $row->current_session_id;

                $row->forceFill([
                    'current_session_id' => $newId,
                    'current_device_id' => $deviceId,
                    'last_login_at' => $now,
                ])->save();

                return $old;
            });
        } catch (Throwable $e) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw $e;
        }

        $user->forceFill([
            'current_session_id' => $newId,
            'current_device_id' => $deviceId,
            'last_login_at' => $now,
        ])->syncOriginal();

        if (is_string($old) && $old !== '' && $old !== self::LOGGED_OUT && $old !== $newId) {
            $this->killSession($old, self::REASON_REPLACED, $deviceId);
        }
    }

    /**
     * Đăng xuất chủ động (AC3): đặt `logged_out` CHỈ khi phiên hiện tại vẫn là phiên hiện hành.
     * Không tạo tombstone.
     */
    public function release(User $user, string $sessionId): void
    {
        if ($user->role !== UserRole::Student) {
            return;
        }

        User::query()
            ->whereKey($user->getKey())
            ->where('current_session_id', $sessionId)
            ->update(['current_session_id' => self::LOGGED_OUT]);
    }

    /**
     * Huỷ phiên hiện hành của học sinh vì lý do hệ thống (đổi/đặt lại mật khẩu, khoá tài khoản —
     * T27 và luồng khoá gọi vào đây). Ghi tombstone + xoá session khỏi store + đặt `logged_out`.
     */
    public function revoke(User $user, string $reason): void
    {
        if ($user->role !== UserRole::Student) {
            return;
        }

        $old = DB::transaction(function () use ($user): ?string {
            $row = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $old = $row->current_session_id;

            $row->forceFill(['current_session_id' => self::LOGGED_OUT])->save();

            return $old;
        });

        if (is_string($old) && $old !== '' && $old !== self::LOGGED_OUT) {
            $this->killSession($old, $reason, null);
        }
    }

    /**
     * Xoá session khỏi store + ghi tombstone. Tombstone ghi TRƯỚC khi destroy để không có khoảng
     * trống nào request cũ thấy "chưa đăng nhập" mà không có lý do. Lỗi store/cache không làm hỏng
     * đăng nhập mới: phiên cũ vẫn bị middleware chặn vì không khớp `current_session_id`.
     */
    private function killSession(string $sessionId, string $reason, ?string $newDeviceId): void
    {
        try {
            Cache::put(
                self::tombstoneKey($sessionId),
                ['reason' => $reason, 'new_device_id' => $newDeviceId, 'at' => now()->toIso8601String()],
                now()->addMinutes((int) config('session.lifetime')),
            );
        } catch (Throwable $e) {
            Log::warning('student_session.tombstone_failed', ['exception' => $e::class]);
        }

        try {
            Session::getHandler()->destroy($sessionId);
        } catch (Throwable $e) {
            Log::warning('student_session.destroy_failed', ['exception' => $e::class]);
        }
    }
}
