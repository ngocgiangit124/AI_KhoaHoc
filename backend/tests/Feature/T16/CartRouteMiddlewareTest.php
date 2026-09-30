<?php

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

/**
 * T16 — 5 route giỏ hàng (api-contract §2.3) phải nằm trong nhóm `student`
 * chuẩn (api-contract §1.3); KHÔNG có `account.verified`/`parent.consent` (chỉ
 * checkout — T18); riêng áp mã có `throttle:coupon`. Bổ sung cho
 * `RouteMiddlewareGroupsTest` (T02) quét mọi route `auth:sanctum` — nó cũng
 * tự bao phủ các route này.
 */
function vvCartRoutes(): array
{
    $routes = [];

    /** @var RouteObject $route */
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'api.cart.')) {
            $routes[(string) $route->getName()] = $route;
        }
    }

    return $routes;
}

test('co du 5 route gio hang tren host api', function () {
    expect(array_keys(vvCartRoutes()))->toEqualCanonicalizing([
        'api.cart.show',
        'api.cart.items.store',
        'api.cart.items.destroy',
        'api.cart.coupon.update',
        'api.cart.coupon.destroy',
    ]);

    foreach (vvCartRoutes() as $route) {
        expect($route->getDomain())->toBe(config('app.api_host'));
    }
});

test('moi route gio hang co du middleware nhom student', function () {
    foreach (vvCartRoutes() as $name => $route) {
        $middleware = $route->gatherMiddleware();

        foreach (['auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'] as $required) {
            expect(in_array($required, $middleware, true))->toBeTrue("{$name} thiếu {$required}");
        }

        foreach (['account.verified', 'parent.consent'] as $forbidden) {
            expect(in_array($forbidden, $middleware, true))->toBeFalse("{$name} không được đòi {$forbidden} (chỉ checkout)");
        }
    }
});

test('chi PUT /cart/coupon co throttle:coupon', function () {
    foreach (vvCartRoutes() as $name => $route) {
        $has = in_array('throttle:coupon', $route->gatherMiddleware(), true);

        expect($has)->toBe($name === 'api.cart.coupon.update', $name);
    }
});

test('route xoa dong gio dung id so, khong model binding (khong lo ton tai khoa)', function () {
    $route = vvCartRoutes()['api.cart.items.destroy'];

    expect($route->allowsTrashedBindings())->toBeFalse();
    expect($route->getAction('controller'))->toContain('CartItemController@destroy');
    expect($route->wheres)->toHaveKey('courseId');
    expect($route->parameterNames())->toBe(['courseId']);
});
