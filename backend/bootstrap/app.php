<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ConfigureHostContext;
use App\Http\Middleware\EnforceSingleStudentSession;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAccountVerified;
use App\Http\Middleware\EnsureAdminOrigin;
use App\Http\Middleware\EnsureGuestStudent;
use App\Http\Middleware\EnsurePasswordFresh;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStaffMfaPassed;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StaffIdleTimeout;
use App\Http\Middleware\TrustHosts;
use App\Support\ApiExceptionRenderer;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // routes/admin.php tự bọc Route::domain(config('app.admin_api_host'))
            // + prefix('api/v1') bên trong file (route quản trị chỉ tồn tại trên
            // host admin-api — ADR-004 §2.1).
            Route::middleware('api')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Thứ tự cuối cùng của middleware toàn cục phụ thuộc THỨ TỰ GỌI, không phải
        // thứ tự khai báo trong file này: mỗi prepend() chèn vào ĐẦU danh sách hiện
        // có, nên lệnh gọi SAU CÙNG sẽ chạy TRƯỚC TIÊN. Thứ tự thực thi đích:
        // [AssignRequestId, TrustProxies, TrustHosts, ConfigureHostContext,
        // SecurityHeaders, ...mặc định (HandleCors, ...)]. TrustProxies PHẢI chạy
        // trước TrustHosts/ConfigureHostContext (H1 — review bảo mật T01/T02):
        // nếu không, khi có proxy tin cậy (TRUSTED_PROXIES ở staging/production),
        // ConfigureHostContext/Router chọn cookie/CORS/route theo Host gốc trong
        // lúc TrustProxies (chạy sau) lại khiến EncryptCookies/router đọc theo
        // X-Forwarded-Host — 2 middleware xử lý 2 "host" khác nhau, phá ranh giới
        // học sinh/quản trị. Ta gọi prepend() theo thứ tự NGƯỢC LẠI bên dưới. Đừng
        // sắp xếp lại theo cảm tính — luôn kiểm bằng test khi đổi (xem
        // GlobalMiddlewareOrderTest).
        //
        // Không dùng $middleware->trustHosts() (helper mặc định của Laravel):
        // 1) nó luôn đặt TrustHosts sau các prepend() (không control được vị trí
        //    tương đối với ConfigureHostContext);
        // 2) TrustHosts mặc định của Laravel tự bỏ qua khi app()->environment('local')
        //    hoặc khi chạy test — sai với yêu cầu của dự án (2 host là ranh giới bảo
        //    mật thật, phải luôn bật). Dùng App\Http\Middleware\TrustHosts (override
        //    shouldSpecifyTrustedHosts()) + gọi thẳng TrustHosts::at() để cấu hình.
        $middleware->prepend(SecurityHeaders::class);
        $middleware->prepend(ConfigureHostContext::class);

        TrustHosts::at(fn () => [
            '^'.preg_quote((string) config('app.api_host'), '#').'$',
            '^'.preg_quote((string) config('app.admin_api_host'), '#').'$',
        ], subdomains: false);
        $middleware->prepend(TrustHosts::class);

        // H1 — chỉ tin X-Forwarded-For/Port/Proto (IP thật + scheme phía sau LB),
        // KHÔNG BAO GIỜ tin X-Forwarded-Host/Prefix: Host là ranh giới bảo mật
        // giữa 2 host api/admin-api (S6), Nginx ở origin luôn nhận đúng Host thật
        // nên không cần (và không được) tin header do client/LB tự khai.
        // env() trực tiếp (không phải config('app.trusted_proxies')) là cố ý: closure
        // này chạy lúc Kernel được resolve từ container — TRƯỚC khi 'config' được
        // bind (LoadConfiguration bootstrap) — gọi config() ở đây làm sập bootstrap
        // của Larastan (không phải lỗi khi chạy app thật, nhưng vẫn phải tránh).
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        $trustedProxiesEnv = (string) env('TRUSTED_PROXIES', '');
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxiesEnv))));
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        // $middleware->trustProxies() chỉ CẤU HÌNH (static state), không quyết định
        // VỊ TRÍ trong pipeline — TrustProxies nằm trong danh sách mặc định của
        // Laravel nên luôn chạy SAU mọi prepend() ở trên nếu không tự prepend nó.
        // Phải prepend tường minh ở đây để nó chạy TRƯỚC TrustHosts/ConfigureHostContext.
        $middleware->prepend(TrustProxies::class);

        $middleware->prepend(AssignRequestId::class);

        $middleware->statefulApi();

        // `guest` trên API (còn dùng cho các route guest-only sau này, vd quên mật khẩu): người đã
        // đăng nhập → 403 JSON, không redirect. Login KHÔNG dùng `guest` (ADR-003, T05) và register dùng
        // `guest.student` (bỏ qua phiên cũ đã bị thay thế/đăng xuất) — xem routes/api.php.
        $middleware->redirectUsersTo(function (): never {
            throw new DomainException(
                code: 'FORBIDDEN',
                message: 'Bạn đã đăng nhập. Hãy đăng xuất trước khi thực hiện thao tác này.',
                status: 403,
            );
        });

        // T28 — `admin.origin` PHẢI chạy trước `auth:sanctum`: Laravel sắp middleware có trong danh sách ưu
        // tiên (Authenticate...) lên trước middleware khác, nên không có dòng này thì request tới admin-api
        // từ origin lạ chưa đăng nhập nhận 401 thay vì 403 ORIGIN_NOT_ALLOWED.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureAdminOrigin::class);

        $middleware->alias([
            'no_store' => NoStoreForAuthenticated::class,
            'admin.origin' => EnsureAdminOrigin::class,
            'role' => EnsureRole::class,
            'guest.student' => EnsureGuestStudent::class,
            'account.active' => EnsureAccountActive::class,
            'account.verified' => EnsureAccountVerified::class,
            // Khung cho T28 (api-contract §1.3) — pass-through tới khi hiện thực. `student.single_session` đã hiện thực ở T05.
            'staff.idle' => StaffIdleTimeout::class,
            'staff.mfa_passed' => EnsureStaffMfaPassed::class,
            'staff.password_fresh' => EnsurePasswordFresh::class,
            'student.single_session' => EnforceSingleStudentSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Lỗi nghiệp vụ (4xx/503 do ta chủ động ném) là phản hồi bình thường, không report kèm stack trace:
        // trace có đối số hàm (mã OTP người dùng gõ) — S21.
        $exceptions->dontReport([DomainException::class]);

        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'code',
            'parent_phone',
            'parent_email',
            'captcha_token',
        ]);

        $exceptions->render(function (Throwable $e, $request) {
            if (ApiExceptionRenderer::shouldHandle($request)) {
                return ApiExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
