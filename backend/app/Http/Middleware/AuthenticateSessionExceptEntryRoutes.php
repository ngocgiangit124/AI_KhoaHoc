<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thay `sanctum.middleware.authenticate_session` (pipeline stateful mặc định của Sanctum).
 *
 * Bản gốc ném 401 và flush phiên khi mật khẩu đã đổi ở nơi khác — kể cả ở các route "cửa vào"
 * (lấy CSRF, đăng nhập, đăng ký). Trình duyệt còn giữ cookie phiên cũ khi đó nhận 401 ở `/csrf-token`
 * rồi 419 ở login (T28 BUG-1). Ở các route này không có dữ liệu cần bảo vệ bằng phiên cũ (login/
 * csrf tự thay phiên), nên bỏ qua kiểm tra; mọi route cần đăng nhập vẫn qua `AuthenticateSession`.
 */
class AuthenticateSessionExceptEntryRoutes extends AuthenticateSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isEntryRoute($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function isEntryRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        return in_array($name, ['api.csrf-token', 'admin.csrf-token', 'api.auth.login', 'admin.auth.login', 'api.auth.register'], true);
    }
}
