<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * BE-backlog-1 (mục 6): tiến trình con của race T18 phải dọn SẠCH người tạo khóa/mã do factory sinh (giáo viên của từng khóa,
 * admin tạo mã). Trước đây `setup_multi` (3 khóa) và mã giảm giá để lại giáo viên/admin trong DB test → test liệt kê giáo viên
 * (T36/AdminTeacherProfilesTest) đỏ khi chạy sau T18. Chạy: `pest --group=race`.
 * Kiểm theo TẬP ID cụ thể của lượt chạy (không so tổng dòng toàn bảng) nên không đỏ giả khi DB test có tiến trình khác ghi cùng lúc.
 */
function vvBeb1Worker(array $args): array
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/checkout_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(120);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<int> id người tạo khóa + mã của lượt chạy (đọc TRƯỚC khi dọn) */
function vvBeb1Creators(array $courseIds, ?int $couponId): array
{
    $ids = DB::table('courses')->whereIn('id', $courseIds)->pluck('created_by')->all();
    if ($couponId) {
        $ids = [...$ids, ...DB::table('coupons')->where('id', $couponId)->pluck('created_by')->all()];
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

test('race cleanup: setup_multi + cleanup khong de lai user/khoa/ma nao (giao vien cua 3 khoa va admin tao ma)', function () {
    $ids = vvBeb1Worker(['setup_multi', 2, 5]);
    $creators = vvBeb1Creators($ids['courses'], $ids['coupon']);
    expect($creators)->not->toBeEmpty()->and(count($creators))->toBeGreaterThan(1);
    expect(DB::table('users')->whereIn('id', $creators)->count())->toBe(count($creators));

    vvBeb1Worker(['cleanup', implode(',', $ids['users']), implode(',', $ids['courses']), $ids['coupon'], $ids['creator']]);

    expect(DB::table('users')->whereIn('id', [...$ids['users'], ...$creators])->count())->toBe(0)
        ->and(DB::table('courses')->whereIn('id', $ids['courses'])->count())->toBe(0)
        ->and(DB::table('coupons')->where('id', $ids['coupon'])->count())->toBe(0);
})->group('race');

test('race cleanup: setup co ma (admin tao ma) cung duoc don sach', function () {
    $ids = vvBeb1Worker(['setup', 2, 1, 3]);
    $creators = vvBeb1Creators([$ids['course']], $ids['coupon']);

    vvBeb1Worker(['cleanup', implode(',', $ids['users']), $ids['course'], $ids['coupon'], $ids['creator']]);

    expect(DB::table('users')->whereIn('id', [...$ids['users'], ...$creators])->count())->toBe(0);
})->group('race');
