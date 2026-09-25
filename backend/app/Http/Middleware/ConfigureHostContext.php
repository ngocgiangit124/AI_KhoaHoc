<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware toàn cục, chạy ngay sau TrustHosts (ADR-004 §2.2).
 *
 * Đặt cookie phiên / CORS đúng theo host đã được TrustHosts xác thực, trước khi
 * HandleCors và StartSession chạy:
 * - Host api (học sinh): cookie `vv_session`, SameSite=Lax, 7 ngày trượt,
 *   CORS chỉ cho phép FRONTEND_URL.
 * - Host admin-api (quản trị): cookie `vv_admin_session`, SameSite=Strict,
 *   expire_on_close=true, CORS chỉ cho phép ADMIN_URL.
 * - Host khác (không nằm trong TrustHosts, không nên tới được đây) → 404.
 */
class ConfigureHostContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($host === config('app.api_host')) {
            $this->configureFor(
                cookie: config('session.cookie'),
                sameSite: 'lax',
                lifetimeMinutes: 10080,
                expireOnClose: false,
                allowedOrigin: config('app.frontend_url'),
            );
        } elseif ($host === config('app.admin_api_host')) {
            $this->configureFor(
                cookie: config('session.admin_cookie'),
                sameSite: 'strict',
                // Idle 120' và tối đa 12h thật sự được StaffIdleTimeout (T28) kiểm;
                // lifetime ở đây chỉ là hạn tuyệt đối của cookie trình duyệt.
                lifetimeMinutes: 720,
                expireOnClose: true,
                allowedOrigin: config('app.admin_url'),
            );
        } else {
            abort(404);
        }

        return $next($request);
    }

    private function configureFor(
        string $cookie,
        string $sameSite,
        int $lifetimeMinutes,
        bool $expireOnClose,
        ?string $allowedOrigin,
    ): void {
        config([
            'session.cookie' => $cookie,
            'session.same_site' => $sameSite,
            'session.lifetime' => $lifetimeMinutes,
            'session.expire_on_close' => $expireOnClose,
            'session.domain' => null,
            'cors.allowed_origins' => array_filter([$allowedOrigin]),
        ]);
    }
}
