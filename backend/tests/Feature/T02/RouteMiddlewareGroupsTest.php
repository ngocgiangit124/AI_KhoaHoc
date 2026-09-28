<?php

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

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

        if (! in_array('auth:sanctum', $middleware, true)) {
            continue;
        }

        $checked++;
        $domain = $route->getDomain();
        $uri = $route->uri();

        // Ngoại lệ duy nhất theo api-contract §1.3.
        if (str_ends_with($uri, 'auth/logout')) {
            continue;
        }

        if ($domain === config('app.api_host')) {
            $required = ['account.active', 'student.single_session', 'no_store'];
        } elseif ($domain === config('app.admin_api_host')) {
            $required = ['admin.origin', 'account.active', 'staff.idle', 'no_store'];

            // Chính route MFA/đổi mật khẩu không thể tự đòi hỏi đã qua MFA/mật khẩu mới.
            if (! str_contains($uri, 'auth/mfa') && ! str_contains($uri, 'auth/password')) {
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

    // R4 (review T01/T02): khẳng định có ít nhất 1 route auth:sanctum được
    // quét — tránh assertion vô nghĩa (>= 0 luôn đúng). Cập nhật ở T06 (route
    // admin/subjects — US-011) vì đây là route auth:sanctum đầu tiên của dự
    // án, chạy trước T03/T05 theo lịch trình gốc.
    expect($checked)->toBeGreaterThan(0);
});

/**
 * R1 (review T01/T02) — lưới an toàn bổ sung: route mặc định của package
 * (Sanctum `sanctum/csrf-cookie` trước khi bị `Sanctum::ignoreRoutes()` tắt
 * là ví dụ thật) có thể không khai `Route::domain()` — khi đó nó khớp MỌI
 * host (kể cả admin-api) mà không qua `admin.origin`. Quét mọi route: nếu có
 * thể được dispatch khi `Host: admin-api.localhost` (domain rỗng hoặc đúng
 * admin_api_host) thì bắt buộc có middleware `admin.origin`.
 */
test('moi route co the goi tren host admin-api deu co middleware admin.origin', function () {
    // Route framework mặc định, không đọc/ghi session/cookie theo host — an toàn:
    // `up` (health-check), `storage/{path}` (phục vụ file disk `local`, middleware
    // `signed`/không, không có state theo host).
    $exemptUris = ['up', 'storage/{path}'];

    $violations = [];

    /** @var RouteObject $route */
    foreach (Route::getRoutes() as $route) {
        $domain = $route->getDomain();
        $uri = $route->uri();

        $reachableOnAdminHost = $domain === null || $domain === config('app.admin_api_host');

        if (! $reachableOnAdminHost || in_array($uri, $exemptUris, true)) {
            continue;
        }

        if (! in_array('admin.origin', $route->gatherMiddleware(), true)) {
            $violations[] = "{$uri} (domain=".($domain ?? 'null').") thiếu middleware 'admin.origin'";
        }
    }

    expect($violations)->toBe([]);
});
