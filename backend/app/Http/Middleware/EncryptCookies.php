<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum::EnsureFrontendRequestsAreStateful ép cứng `session.same_site = 'lax'`
 * ngay trước khi đẩy EncryptCookies/StartSession vào pipeline (bất kể host).
 * Class này (bind qua `config('sanctum.middleware.encrypt_cookies')`) là middleware
 * đầu tiên Sanctum thực thi trong pipeline đó — đặt lại đúng SameSite theo host
 * (ADR-004 §2.2: admin-api = Strict) trước khi StartSession đọc config.
 *
 * H1 (review bảo mật T01/T02): đọc host từ `$request->attributes` (đặt bởi
 * ConfigureHostContext — đã chạy sau TrustProxies, chỉ tin X-Forwarded-For/
 * Port/Proto) thay vì tự gọi lại `getHost()`, để không lệch host với
 * ConfigureHostContext nếu logic tin proxy thay đổi sau này.
 */
class EncryptCookies extends BaseEncryptCookies
{
    /**
     * @param  mixed  $request  Giữ đúng kiểu (không type-hint) như lớp cha để không
     *                          vi phạm LSP; ép kiểu Request ngay bên trong.
     */
    public function handle($request, Closure $next): Response
    {
        if ($request instanceof Request) {
            $host = $request->attributes->get(ConfigureHostContext::HOST_ATTRIBUTE, $request->getHost());

            if ($host === config('app.admin_api_host')) {
                config(['session.same_site' => 'strict']);
            }
        }

        return parent::handle($request, $next);
    }
}
