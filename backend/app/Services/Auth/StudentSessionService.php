<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\DeviceId;
use App\Support\SessionTombstoneStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

/**
 * ADR-003 — "1 thiết bị/1 phiên" cho vai trò `hoc_sinh`: đăng nhập ghi
 * `users.current_session_id`/`current_device_id` + huỷ phiên cũ khỏi store +
 * tombstone lý do (đọc bởi `ApiExceptionRenderer`); đăng xuất đặt
 * `logged_out`; đổi mật khẩu (T27)/khoá tài khoản thu hồi theo cùng cơ chế.
 *
 * Quy tắc cho Dev (ADR-003): CHỈ class này được ghi 2 cột trên và tombstone.
 * Bất kỳ luồng nào gọi `session()->regenerate()` cho học sinh đều PHẢI qua
 * `bind()` — không tự gọi `Auth::login()`/`regenerate()` rời rạc.
 */
class StudentSessionService
{
    private const LOGGED_OUT = 'logged_out';

    private const REASON_REPLACED = 'replaced';

    private const REASON_PASSWORD_CHANGED = 'password_changed';

    private const REASON_LOCKED = 'locked';

    /**
     * Đăng nhập/đăng ký thành công (host api). Gọi SAU KHI đã xác thực thông
     * tin đăng nhập (`LoginService`) hoặc tạo tài khoản (`RegistrationService`)
     * — hàm này tự `Auth::login()` + `regenerate()`, KHÔNG gọi trước đó nữa.
     */
    public function bind(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();

        $newSessionId = $request->session()->getId();
        $deviceId = DeviceId::fromRequest($request);

        try {
            $oldSessionId = DB::transaction(function () use ($user, $newSessionId, $deviceId): ?string {
                // Khoá hàng `users` (lockForUpdate) — đọc + ghi
                // current_session_id PHẢI atomic, cùng nguyên tắc chống race
                // của T04 (OtpService::createCodeAtomically()): không để 2
                // lần bind song song (double submit/2 tab) cùng đọc thấy
                // cùng 1 "old" rồi cùng huỷ/tombstone chồng nhau.
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    // Cực hiếm (tài khoản bị xoá đúng lúc đăng nhập) — ném ra
                    // để catch bên dưới huỷ luôn phiên MỚI vừa tạo (ADR-003
                    // bước 5: không được để lọt 2 phiên cùng hợp lệ).
                    throw new RuntimeException('student_session.user_missing_during_bind');
                }

                $old = $locked->current_session_id;

                $locked->forceFill([
                    'current_session_id' => $newSessionId,
                    'current_device_id' => $deviceId,
                    'last_login_at' => now(),
                ])->save();

                return $old;
            });
        } catch (Throwable $e) {
            // ADR-003 bước 5 — lỗi khi ghi phiên mới: huỷ NGAY phiên vừa tạo.
            // Phiên cũ (nếu có) vẫn là phiên duy nhất hợp lệ, không có cửa sổ
            // nào có 2 phiên cùng sống.
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw $e;
        }

        $this->invalidatePrevious($oldSessionId, $newSessionId, self::REASON_REPLACED, $deviceId);
    }

    /**
     * `POST /auth/logout` (host api) — AC3 (US-014): không được ảnh hưởng
     * phiên nào khác ngoài chính phiên hiện tại. UPDATE có điều kiện (chỉ đặt
     * `logged_out` nếu `current_session_id` VẪN khớp phiên hiện tại lúc thao
     * tác): tránh trường hợp hiếm giữa lúc middleware xác nhận phiên hợp lệ và
     * lúc controller chạy tới đây, một thiết bị khác vừa đăng nhập xong (đổi
     * `current_session_id` sang phiên MỚI của họ) — logout không được ghi đè
     * lên phiên mới đó. KHÔNG tạo tombstone (đăng xuất chủ động, không cần lý
     * do đặc biệt — `UNAUTHENTICATED` mặc định là đủ).
     */
    public function logout(User $user, string $currentSessionId): void
    {
        User::query()
            ->whereKey($user->getKey())
            ->where('current_session_id', $currentSessionId)
            ->update(['current_session_id' => self::LOGGED_OUT]);
    }

    /**
     * Thu hồi phiên hiện hành vì đổi/đặt lại mật khẩu (T27, US-015, S11) —
     * chuẩn bị sẵn cho T27 gọi (chưa có endpoint nào dùng ở T05). Test bắt
     * buộc của ADR-003 gọi thẳng hàm này để xác nhận `SESSION_REVOKED`.
     */
    public function revokeForPasswordChange(User $user): void
    {
        $this->revoke($user, self::REASON_PASSWORD_CHANGED);
    }

    /**
     * Thu hồi phiên hiện hành vì tài khoản bị khoá (ADR-003 §"Khoá tài
     * khoản") — chuẩn bị sẵn cho luồng khoá tài khoản học sinh (ngoài phạm vi
     * T05, chưa có nơi nào gọi ở task này).
     */
    public function revokeForLock(User $user): void
    {
        $this->revoke($user, self::REASON_LOCKED);
    }

    private function revoke(User $user, string $reason): void
    {
        $oldSessionId = DB::transaction(function () use ($user): ?string {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $old = $locked->current_session_id;

            $locked->forceFill(['current_session_id' => self::LOGGED_OUT])->save();

            return $old;
        });

        $this->invalidatePrevious($oldSessionId, null, $reason, null);
    }

    /**
     * Tombstone + huỷ phiên cũ khỏi store. Nếu bước này lỗi (Redis tạm gián
     * đoạn), phiên cũ VẪN bị `EnforceSingleStudentSession` chặn ở request kế
     * tiếp (so `current_session_id` đã đổi trong DB) — chỉ mất đi LÝ DO cụ
     * thể hiển thị cho người dùng, không mất an toàn (ADR-003 bước 5). Log
     * cảnh báo để vận hành biết.
     */
    private function invalidatePrevious(?string $oldSessionId, ?string $newSessionId, string $reason, ?string $newDeviceId): void
    {
        if ($oldSessionId === null || $oldSessionId === self::LOGGED_OUT || $oldSessionId === $newSessionId) {
            return;
        }

        try {
            SessionTombstoneStore::put($oldSessionId, [
                'reason' => $reason,
                'new_device_id' => $newDeviceId,
                'at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::warning('student_session.tombstone_write_failed', ['reason' => $reason]);
        }

        try {
            Session::getHandler()->destroy($oldSessionId);
        } catch (Throwable $e) {
            report($e);
            Log::warning('student_session.destroy_old_failed', ['reason' => $reason]);
        }
    }
}
