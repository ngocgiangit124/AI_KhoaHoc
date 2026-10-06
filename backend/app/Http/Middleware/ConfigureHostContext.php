<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware toàn cục, chạy ngay sau TrustProxies + TrustHosts (ADR-004 §2.2).
 *
 * Đặt cookie phiên / CORS đúng theo host đã được TrustHosts xác thực, trước khi
 * HandleCors và StartSession chạy:
 * - Host api (học sinh): cookie `vv_session`, SameSite=Lax, 7 ngày trượt,
 *   CORS chỉ cho phép FRONTEND_URL.
 * - Host admin-api (quản trị): cookie `vv_admin_session`, SameSite=Strict,
 *   expire_on_close=true, CORS chỉ cho phép ADMIN_URL.
 * - Host khác (không nằm trong TrustHosts, không nên tới được đây) → 404.
 *
 * H1 (review bảo mật T01/T02): host được chọn Ở ĐÂY (sau khi TrustProxies đã
 * giới hạn header nào được tin — không tin X-Forwarded-Host) được lưu vào
 * `$request->attributes` (khoá `vv_host`). `EncryptCookies` đọc lại từ đây
 * thay vì tự gọi `getHost()` lần nữa, để không có 2 middleware suy ra 2 "host"
 * khác nhau cho cùng 1 request.
 */
class ConfigureHostContext
{
    public const HOST_ATTRIBUTE = 'vv_host';

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $request->attributes->set(self::HOST_ATTRIBUTE, $host);

        // M4 — Secure luôn bật ở production hoặc khi request thật sự qua HTTPS,
        // không phụ thuộc hoàn toàn vào SESSION_SECURE_COOKIE có được đặt đúng
        // trong .env hay không (quên đặt vẫn an toàn ở production).
        $secure = app()->isProduction() || $request->isSecure();

        if ($host === config('app.api_host')) {
            $this->configureFor(
                cookie: config('session.cookie'),
                sameSite: 'lax',
                lifetimeMinutes: 10080,
                expireOnClose: false,
                allowedOrigin: config('app.frontend_url'),
                secure: $secure,
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
                secure: $secure,
            );
        } elseif (config('videolab.enabled') && $host === config('videolab.host')) {
            // T12 — VideoLab: không phiên/cookie, CORS do VideoLabCors tự xử lý (route không thuộc nhóm web/api).
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
        bool $secure,
    ): void {
        config([
            'session.cookie' => $cookie,
            'session.same_site' => $sameSite,
            'session.lifetime' => $lifetimeMinutes,
            'session.expire_on_close' => $expireOnClose,
            'session.domain' => null,
            'session.secure' => $secure,
            'cors.allowed_origins' => array_filter([$allowedOrigin]),
        ]);
    }
}
