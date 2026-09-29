<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\DeviceId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `student.single_session` (T05, ADR-003) — 1 thiết bị/1 phiên cho vai
 * trò `hoc_sinh`. Giáo Viên/Quản lý trang/Admin không bị giới hạn (BR4).
 *
 * Xử lý trường hợp phiên cũ CÒN dữ liệu trong session store (chưa bị
 * `StudentSessionService` xoá — bình thường xảy ra ngay khi request của
 * thiết bị cũ đi TỚI TRƯỚC lúc `bind()` của thiết bị mới hoàn tất, hoặc
 * hiếm hơn là bước xoá ở `bind()` bị lỗi): `auth:sanctum` (chạy trước
 * middleware này) vẫn xác thực được vì session cũ còn nguyên, NHƯNG
 * `users.current_session_id` đã trỏ sang phiên khác — phải từ chối tại đây.
 *
 * KHÔNG có nhánh "nhận nuôi" phiên NULL/khác id nào (S11 — lỗi của bản thiết
 * kế cũ khiến phiên ở máy công cộng "sống lại" sau khi chủ tài khoản đăng
 * xuất ở máy khác): mọi trường hợp current_session_id khác id hiện tại (kể cả
 * NULL/`logged_out`) đều bị từ chối như nhau.
 */
class EnforceSingleStudentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || $user->role !== UserRole::Student) {
            return $next($request);
        }

        $currentSessionId = $request->session()->getId();

        if ($user->current_session_id === $currentSessionId) {
            return $next($request);
        }

        // Đăng nhập cùng `device_id` với `current_device_id` đang hoạt động
        // (đúng như đã bind) → đây là chính thiết bị này (bấm đăng nhập 2 lần
        // hoặc tải lại sau khi phiên khác trên CÙNG máy thay nó) → SESSION_EXPIRED,
        // không báo nhầm "thiết bị khác" (ADR-003).
        $deviceId = DeviceId::normalize($request->header('X-Device-Id'));
        $isSameDevice = $deviceId !== null && $deviceId === $user->current_device_id;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new DomainException(
            code: $isSameDevice ? 'SESSION_EXPIRED' : 'SESSION_REPLACED',
            message: $isSameDevice
                ? 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.'
                : 'Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay.',
            status: 401,
        );
    }
}
