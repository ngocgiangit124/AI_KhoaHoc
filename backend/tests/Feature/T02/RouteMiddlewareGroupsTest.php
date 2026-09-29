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
    // T28 — `admin.auth.login` chưa đăng nhập (guest), không thể tự đòi
    // auth:sanctum. `admin.auth.mfa.verify` CÓ auth:sanctum (phiên đang chờ
    // MFA) nhưng cố tình KHÔNG có `role:...`: đây là route công khai cho MỌI
    // phiên vừa đăng nhập (kể cả GV gọi nhầm — chỉ nhận lỗi mã OTP sai, không
    // rò rỉ gì thêm), test riêng cho nhóm auth:sanctum của nó nằm ở
    // `VV_ADMIN_MFA_EXEMPT_ROUTE_NAMES` bên dưới.
    'admin.auth.login',
    'admin.auth.mfa.verify',
];

/**
 * R1 (review-T28.md, [BLOCKER]) — TÁCH RIÊNG khỏi allowlist miễn
 * `staff.password_fresh`: trước đây 1 hằng số DUY NHẤT (`VV_ADMIN_MFA_OR_PASSWORD_ROUTE_NAMES`)
 * miễn CẢ HAI middleware cho CẢ `admin.auth.mfa.verify` LẪN
 * `admin.auth.password.update`, khiến việc đổi mật khẩu vô tình được phép
 * bỏ qua MFA (lỗ hổng: biết đúng mật khẩu nhưng CHƯA qua MFA vẫn đổi được
 * mật khẩu, vô hiệu hoá tác dụng của MFA). Chỉ CHÍNH route MFA mới được miễn
 * `staff.mfa_passed` (nó là route để ĐẠT được trạng thái đó).
 *
 * @var list<string>
 */
const VV_ADMIN_MFA_EXEMPT_ROUTE_NAMES = [
    'admin.auth.mfa.verify',
];

/**
 * Route được miễn `staff.password_fresh` vì chính nó là lối thoát DUY NHẤT
 * khỏi `must_change_password` (M1, L2) — `admin.auth.password.update` VẪN
 * PHẢI có `staff.mfa_passed` (xem `VV_ADMIN_MFA_EXEMPT_ROUTE_NAMES` — R1).
 *
 * @var list<string>
 */
const VV_ADMIN_PASSWORD_FRESH_EXEMPT_ROUTE_NAMES = [
    'admin.auth.mfa.verify',
    'admin.auth.password.update',
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
            // S19 (T03) — role:hoc_sinh là lớp phòng thủ bổ sung: host api chỉ
            // đăng nhập được vai trò hoc_sinh (LoginService trả WRONG_PORTAL cho
            // vai trò khác), nhưng mọi route auth:sanctum vẫn phải tự khai rõ.
            $required = ['account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'];
        } elseif ($domain === config('app.admin_api_host')) {
            // R3 (review-T28.md) — `staff.session` (Sanctum `AuthenticateSession`)
            // là cơ chế DUY NHẤT thực thi "đổi mật khẩu huỷ phiên khác" (DoD
            // T28); bắt buộc cho MỌI route auth:sanctum trên host này, không
            // có ngoại lệ (kể cả route MFA/đổi mật khẩu — vô hại, xem
            // `routes/admin.php`), để route admin thêm sau (T08+) không thể
            // lỡ quên mà không bị lưới này bắt.
            $required = ['admin.origin', 'account.active', 'staff.idle', 'staff.session', 'no_store'];

            // R1 (review-T28.md) — 2 allowlist TÁCH RIÊNG: chỉ CHÍNH route MFA
            // được miễn `staff.mfa_passed` (nó là route để ĐẠT được trạng
            // thái đó); route đổi mật khẩu VẪN PHẢI qua MFA trước (đóng lỗ
            // hổng "biết mật khẩu, chưa qua MFA, vẫn đổi được mật khẩu") —
            // chỉ được miễn `staff.password_fresh` (lối thoát duy nhất khỏi
            // `must_change_password`). (L2 — allowlist theo TÊN route, không
            // dùng str_contains trên URI.)
            if ($name === null || ! in_array($name, VV_ADMIN_MFA_EXEMPT_ROUTE_NAMES, true)) {
                $required[] = 'staff.mfa_passed';
            }

            if ($name === null || ! in_array($name, VV_ADMIN_PASSWORD_FRESH_EXEMPT_ROUTE_NAMES, true)) {
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

    // R4 (review T01/T02) — T03 đã thêm route auth:sanctum đầu tiên
    // (auth/logout, auth/me); T06 thêm thêm nhóm admin/subjects: khẳng định có
    // ít nhất 1 route được xét, không còn là assertion "xanh giả" (>= 0 luôn đúng).
    expect($checked)->toBeGreaterThan(0);
});

/**
 * S19 (T03, ghi chú Security ở docs/security/review-T01-T02.md) — mirror của
 * `vvAdminRouteViolations()` cho host api: mọi route CÓ THỂ được dispatch khi
 * `Host: api.localhost` và có `auth:sanctum` (trừ `auth/logout` — ngoại lệ duy
 * nhất theo api-contract §1.3) phải có `role:hoc_sinh`.
 *
 * @return list<string>
 */
function vvStudentRouteViolations(): array
{
    $violations = [];

    /** @var RouteObject $route */
    foreach (Route::getRoutes() as $route) {
        $domain = $route->getDomain();
        $uri = $route->uri();
        $name = $route->getName();
        $middleware = $route->gatherMiddleware();

        $reachableOnApiHost = $domain === null || $domain === config('app.api_host');

        if (! $reachableOnApiHost || ! vvHasAuthSanctum($middleware)) {
            continue;
        }

        if (str_ends_with($uri, 'auth/logout')) {
            continue;
        }

        if (! in_array('role:hoc_sinh', $middleware, true)) {
            $violations[] = "{$uri} (tên: ".($name ?? '—').") thiếu middleware 'role:hoc_sinh'";
        }
    }

    return $violations;
}

test('moi route auth:sanctum tren host api (tru auth/logout) co role:hoc_sinh (S19)', function () {
    expect(vvStudentRouteViolations())->toBe([]);
});

test('logic kiem tra bat duoc route hoc sinh gia thieu role:hoc_sinh (S19)', function () {
    Route::domain(config('app.api_host'))
        ->middleware(['auth:sanctum'])
        ->name('api.__test.student-missing-role')
        ->get('/__test/student-missing-role', fn () => response()->json(['ok' => true]));

    $violations = vvStudentRouteViolations();

    expect($violations)->not->toBe([]);
    expect(collect($violations)->contains(fn ($v) => str_contains($v, 'student-missing-role')))->toBeTrue();
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
