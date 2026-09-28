<?php

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

/**
 * Route trên admin-api được phép KHÔNG có `auth:sanctum`/`role:...` vì hợp lệ
 * khi CHƯA đăng nhập (M1, L2 — allowlist theo TÊN route, không dùng
 * `str_contains` rộng trên URI).
 *
 * @var list<string>
 */
const VV_ADMIN_PUBLIC_ROUTE_NAMES = [
    'admin.csrf-token',
    // T28 sẽ thêm: 'admin.auth.login', 'admin.auth.mfa.verify' (đăng nhập trước
    // khi có session hợp lệ, không thể tự đòi auth:sanctum).
];

/**
 * Route đã có `auth:sanctum` nhưng KHÔNG cần `staff.mfa_passed`/
 * `staff.password_fresh` vì chính nó là route MFA/đổi mật khẩu (M1, L2).
 *
 * @var list<string>
 */
const VV_ADMIN_MFA_OR_PASSWORD_ROUTE_NAMES = [
    // T28 sẽ thêm: 'admin.auth.mfa.verify', 'admin.auth.password.update'.
];

function vvHasAuthSanctum(array $middleware): bool
{
    foreach ($middleware as $m) {
        if (str_starts_with($m, 'auth:sanctum')) {
            return true;
        }
    }

    return false;
}

/**
 * Logic dùng chung: quét toàn bộ route CÓ THỂ được dispatch khi
 * `Host: admin-api.localhost` (domain rỗng — khớp mọi host — hoặc đúng
 * admin_api_host), bắt buộc có `admin.origin`, và (trừ allowlist) bắt buộc có
 * `auth:sanctum` + (`role:...` hoặc `can:access-admin-area`).
 *
 * @return list<string>
 */
function vvAdminRouteViolations(): array
{
    // Route framework mặc định, không đọc/ghi session/cookie theo host — an toàn.
    $exemptUris = ['up'];

    $violations = [];

    /** @var RouteObject $route */
    foreach (Route::getRoutes() as $route) {
        $domain = $route->getDomain();
        $uri = $route->uri();
        $name = $route->getName();
        $middleware = $route->gatherMiddleware();

        $reachableOnAdminHost = $domain === null || $domain === config('app.admin_api_host');

        if (! $reachableOnAdminHost || in_array($uri, $exemptUris, true)) {
            continue;
        }

        if (! in_array('admin.origin', $middleware, true)) {
            $violations[] = "{$uri} (domain=".($domain ?? 'null').") thiếu middleware 'admin.origin'";
        }

        if ($name !== null && in_array($name, VV_ADMIN_PUBLIC_ROUTE_NAMES, true)) {
            continue;
        }

        if (! vvHasAuthSanctum($middleware)) {
            $violations[] = "{$uri} (tên: ".($name ?? '—').") thiếu middleware 'auth:sanctum'";

            continue;
        }

        $hasRoleOrAbility = false;

        foreach ($middleware as $m) {
            if (str_starts_with($m, 'role:') || str_starts_with($m, 'can:access-admin-area')) {
                $hasRoleOrAbility = true;

                break;
            }
        }

        if (! $hasRoleOrAbility) {
            $violations[] = "{$uri} (tên: ".($name ?? '—').") thiếu 'role:...' hoặc 'can:access-admin-area'";
        }
    }

    return $violations;
}

/**
 * S19 — mọi route có `auth:sanctum` phải có đủ middleware chuẩn của nhóm
 * (api-contract §1.3). Hiện T01/T02 chưa có route bảo vệ nào (thêm dần từ
 * T03), nên test này chạy "rỗng" nhưng là lưới an toàn bắt buộc phải xanh
 * ngay khi các task sau thêm route `auth:sanctum` đầu tiên.
 */
test('moi route auth:sanctum co du middleware chuan theo host', function () {
    $violations = [];
    $checked = 0;

    /** @var RouteObject $route */
    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! vvHasAuthSanctum($middleware)) {
            continue;
        }

        $checked++;
        $domain = $route->getDomain();
        $uri = $route->uri();
        $name = $route->getName();

        // Ngoại lệ duy nhất theo api-contract §1.3.
        if (str_ends_with($uri, 'auth/logout')) {
            continue;
        }

        if ($domain === config('app.api_host')) {
            $required = ['account.active', 'student.single_session', 'no_store'];
        } elseif ($domain === config('app.admin_api_host')) {
            $required = ['admin.origin', 'account.active', 'staff.idle', 'no_store'];

            // Chính route MFA/đổi mật khẩu không thể tự đòi hỏi đã qua MFA/mật khẩu mới
            // (L2 — allowlist theo TÊN route, không dùng str_contains trên URI).
            if ($name === null || ! in_array($name, VV_ADMIN_MFA_OR_PASSWORD_ROUTE_NAMES, true)) {
                $required[] = 'staff.mfa_passed';
                $required[] = 'staff.password_fresh';
            }
        } else {
            $violations[] = "{$uri}: route auth:sanctum nằm ngoài 2 host đã biết ({$domain})";

            continue;
        }

        foreach ($required as $alias) {
            if (! in_array($alias, $middleware, true)) {
                $violations[] = "{$uri} ({$domain}) thiếu middleware '{$alias}'";
            }
        }
    }

    expect($violations)->toBe([]);

    // R4 (review T01/T02): khẳng định đúng số route auth:sanctum hiện có (0 ở
    // T01/T02) thay vì assertion vô nghĩa (>= 0 luôn đúng). TODO(T03): đổi
    // thành `expect($checked)->toBeGreaterThan(0)` khi route auth:sanctum đầu
    // tiên (auth/me, .../otp/*...) được thêm — nếu quên đổi, test này sẽ FAIL
    // và nhắc phải cập nhật, không "xanh giả".
    expect($checked)->toBe(0);
});

/**
 * R1 (review T01/T02) — lưới an toàn bổ sung: route mặc định của package
 * (Sanctum `sanctum/csrf-cookie` trước khi bị tắt là ví dụ thật) có thể không
 * khai `Route::domain()` — khi đó nó khớp MỌI host (kể cả admin-api) mà không
 * qua `admin.origin`.
 */
test('moi route tren admin-api co du admin.origin + auth:sanctum + role (M1)', function () {
    expect(vvAdminRouteViolations())->toBe([]);
});

test('logic kiem tra bat duoc route admin gia chi co auth:sanctum, thieu role (M1)', function () {
    Route::domain(config('app.admin_api_host'))
        ->prefix('api/v1')
        ->middleware(['admin.origin', 'auth:sanctum'])
        ->name('admin.__test.missing-role')
        ->get('/__test/missing-role', fn () => response()->json(['ok' => true]));

    $violations = vvAdminRouteViolations();

    expect($violations)->not->toBe([]);
    expect(collect($violations)->contains(fn ($v) => str_contains($v, 'missing-role')))->toBeTrue();
});

test('logic kiem tra bat duoc route admin gia thieu ca auth:sanctum (M1)', function () {
    Route::domain(config('app.admin_api_host'))
        ->prefix('api/v1')
        ->middleware(['admin.origin'])
        ->name('admin.__test.missing-auth')
        ->get('/__test/missing-auth', fn () => response()->json(['ok' => true]));

    $violations = vvAdminRouteViolations();

    expect($violations)->not->toBe([]);
    expect(collect($violations)->contains(fn ($v) => str_contains($v, 'missing-auth')))->toBeTrue();
});

test('logic kiem tra bat duoc route admin gia thieu admin.origin (M1)', function () {
    Route::domain(config('app.admin_api_host'))
        ->prefix('api/v1')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('admin.__test.missing-origin')
        ->get('/__test/missing-origin', fn () => response()->json(['ok' => true]));

    $violations = vvAdminRouteViolations();

    expect($violations)->not->toBe([]);
    expect(collect($violations)->contains(fn ($v) => str_contains($v, 'missing-origin')))->toBeTrue();
});

/**
 * L1 (review bảo mật T01/T02) — route `storage/{path}` (GET ServeFile + PUT
 * ReceiveFile) không có domain/middleware, chữ ký không gắn host: TẮT hẳn
 * bằng `filesystems.disks.local.serve = false` thay vì chỉ miễn trừ khỏi test.
 */
test('route storage.local va storage.local.upload khong con duoc dang ky (L1)', function () {
    expect(Route::has('storage.local'))->toBeFalse();
    expect(Route::has('storage.local.upload'))->toBeFalse();
});
