<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\GlobalLimit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * GL-A34 (A4) — test kiến trúc: mọi route GHI (POST/PUT/PATCH/DELETE) trên host api và admin-api phải có
 * middleware `throttle:*`. Thêm route ghi mới mà quên throttle thì test này đỏ.
 *
 * Ngoại lệ phải có lý do; KHÔNG thêm bừa.
 */
function vvWriteRoutesWithoutThrottle(): array
{
    $exceptions = [
        // Đăng xuất: chỉ huỷ phiên của chính người gọi, không tốn tài nguyên, không có tác dụng với kẻ ngoài.
        'api.auth.logout' => 'logout chỉ huỷ phiên hiện tại',
        'admin.auth.logout' => 'logout chỉ huỷ phiên hiện tại',
    ];

    $missing = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
        if ($writes === []) {
            continue;
        }
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/') && ! str_starts_with($uri, 'admin/')) {
            continue; // route nội bộ framework (vd storage) nếu có
        }
        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
        $name = (string) $route->getName();
        if (! $hasThrottle && ! array_key_exists($name, $exceptions)) {
            $missing[] = implode('|', $writes).' '.($route->getDomain() ?? '').'/'.$uri.' ('.($name ?: 'no-name').')';
        }
    }

    return $missing;
}

test('GL-A34: mọi route ghi trên api và admin-api có middleware throttle', function () {
    expect(vvWriteRoutesWithoutThrottle())->toBe([]);
});

test('GL-A34: admin-write chỉ đếm method ghi, 120/phút/người; GET không bị đếm', function () {
    $limiter = RateLimiter::limiter('admin-write');
    $user = User::factory()->make(['id' => 77]);

    $get = Request::create('/x', 'GET');
    expect($limiter($get))->toBeInstanceOf(GlobalLimit::class);

    $post = Request::create('/x', 'POST');
    $post->setUserResolver(fn () => $user);
    $limit = $limiter($post);
    expect($limit->maxAttempts)->toBe(120)->and($limit->key)->toContain('admin-write:77');
});
