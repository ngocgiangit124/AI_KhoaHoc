<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.idle` (T28 — ADR-004 §2.2, api-contract §1.7) — phiên quản trị
 * hết hạn khi KHÔNG hoạt động quá `auth.staff.idle_minutes` (mặc định 120
 * phút) HOẶC tổng thời lượng phiên vượt `auth.staff.max_hours` (mặc định 12
 * giờ) kể từ lúc đăng nhập, dù vẫn còn hoạt động liên tục.
 *
 * `session.lifetime` (đặt bởi `ConfigureHostContext`, 720 phút) chỉ là hạn
 * TUYỆT ĐỐI của cookie trình duyệt và TỰ TRƯỢT theo hoạt động (hành vi mặc
 * định của `StartSession`) — không tự thực thi được 1 trong 2 quy tắc trên,
 * nên middleware này là nơi DUY NHẤT kiểm cả hai.
 *
 * `staff_login_at`/`staff_last_activity` được đặt bởi
 * `Admin\Auth\LoginController`/`Admin\Auth\MfaController` (thời điểm coi như
 * phiên "thật sự" bắt đầu — sau khi mật khẩu đúng, không phải sau MFA, để
 * không kéo dài thời lượng tối đa bằng cách trì hoãn bước MFA).
 */
class StaffIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now();

        $loginAt = $session->get('staff_login_at');
        $lastActivity = $session->get('staff_last_activity');

        $idleMinutes = (int) config('auth.staff.idle_minutes');
        $maxHours = (int) config('auth.staff.max_hours');

        $expired = $loginAt === null
            || $lastActivity === null
            || Carbon::createFromTimestamp((int) $lastActivity)->addMinutes($idleMinutes)->isPast()
            || Carbon::createFromTimestamp((int) $loginAt)->addHours($maxHours)->isPast();

        if ($expired) {
            Auth::guard('web')->logout();
            $session->invalidate();

            throw new DomainException(
                code: 'STAFF_IDLE_TIMEOUT',
                message: 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.',
                status: 401,
            );
        }

        $session->put('staff_last_activity', $now->timestamp);

        return $next($request);
    }
}
