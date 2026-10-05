<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `guest.student` — thay `guest:web` cho route đăng ký trên host api (ADR-003, T05).
 *
 * Người đang đăng nhập với phiên còn là phiên hiện hành → 403 `FORBIDDEN`. Học sinh có session cookie
 * nhưng không còn là phiên hiện hành (bị thay thế, đã `logged_out`) thì coi như khách để không
 * che mất lý do mất phiên bằng FORBIDDEN.
 */
class EnsureGuestStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user !== null) {
            $stale = $user->role === UserRole::Student
                && $request->hasSession()
                && $user->current_session_id !== $request->session()->getId();

            if (! $stale) {
                throw new DomainException(
                    code: 'FORBIDDEN',
                    message: 'Bạn đã đăng nhập. Hãy đăng xuất trước khi thực hiện thao tác này.',
                    status: 403,
                );
            }

            // Phiên cũ không còn hiệu lực: đi tiếp như khách (startSession sẽ thay phiên).
        }

        return $next($request);
    }
}
