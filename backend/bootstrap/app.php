<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ConfigureHostContext;
use App\Http\Middleware\EnforceSingleStudentSession;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdminOrigin;
use App\Http\Middleware\EnsurePasswordFresh;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStaffMfaPassed;
use App\Http\Middleware\NoStoreForAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StaffIdleTimeout;
use App\Http\Middleware\TrustHosts;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        // có, nên lệnh gọi SAU CÙNG sẽ chạy TRƯỚC TIÊN. Để có thứ tự thực thi
        // [AssignRequestId, TrustHosts, ConfigureHostContext, SecurityHeaders,
        // ...mặc định (TrustProxies, HandleCors, ...)], ta gọi prepend() theo thứ
        // tự NGƯỢC LẠI bên dưới. Đừng sắp xếp lại theo cảm tính — luôn kiểm bằng
        // test khi đổi.
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

        $middleware->prepend(AssignRequestId::class);

        // env() trực tiếp (không phải config('app.trusted_proxies')) là cố ý: closure
        // này chạy lúc Kernel được resolve từ container — TRƯỚC khi 'config' được
        // bind (LoadConfiguration bootstrap) — gọi config() ở đây làm sập bootstrap
        // của Larastan (không phải lỗi khi chạy app thật, nhưng vẫn phải tránh).
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        $trustedProxiesEnv = (string) env('TRUSTED_PROXIES', '');
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxiesEnv))));
        $middleware->trustProxies(at: $trustedProxies);

        $middleware->statefulApi();

        $middleware->alias([
            'no_store' => NoStoreForAuthenticated::class,
            'admin.origin' => EnsureAdminOrigin::class,
            'role' => EnsureRole::class,
            'account.active' => EnsureAccountActive::class,
            // Khung cho T05/T28 (api-contract §1.3) — pass-through tới khi hiện thực.
            'staff.idle' => StaffIdleTimeout::class,
            'staff.mfa_passed' => EnsureStaffMfaPassed::class,
            'staff.password_fresh' => EnsurePasswordFresh::class,
            'student.single_session' => EnforceSingleStudentSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
