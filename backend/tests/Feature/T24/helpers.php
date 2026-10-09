<?php

use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T28/helpers.php';
require_once __DIR__.'/../T38/helpers.php';

/** Thêm dòng đơn với khóa/tên cho trước (id tăng dần theo thứ tự gọi). */
function vvT24Item(Order $order, Course $course, ?string $title = null, int $final = 100000): void
{
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'course_id' => $course->id, 'course_title' => $title ?? $course->title,
        'unit_price' => $final, 'discount_amount' => 0, 'final_amount' => $final,
    ]);
}

/**
 * Đơn bất kỳ kèm `$items` dòng (mỗi dòng một khóa mới). `$state` là thuộc tính ghi đè, `$factoryState` tên state OrderFactory.
 *
 * @param  array<string, mixed>  $state
 */
function vvT24Order(?User $student = null, array $state = [], string $factoryState = 'manual', int $items = 1): Order
{
    $student ??= User::factory()->student()->verified()->create();
    $factory = $factoryState === 'pending' ? Order::factory() : Order::factory()->{$factoryState}();
    // Mặc định là đơn `manual` (ghi đè bằng `$state['payment_method']`).
    $order = $factory->create(array_merge(['user_id' => $student->id, 'payment_method' => 'manual'], $state));

    for ($i = 1; $i <= $items; $i++) {
        vvT24Item($order, Course::factory()->published()->paid(100000)->create(), "Khoa {$order->code}-{$i}");
    }

    return $order;
}

function vvT24Post(string $path, array $payload = [])
{
    app('auth')->forgetGuards();

    return test()->postJson(vvAdminUrl($path), $payload, vvAdminHeaders());
}

/** Khoảng ngày bao hôm nay và 2 ngày trước (đơn "30 giờ trước" vẫn lọt khi test chạy lúc 0h–6h) để danh sách luôn thấy đơn vừa tạo. */
function vvT24Range(): string
{
    return 'from='.now()->subDays(2)->format('Y-m-d').'&to='.now()->addDay()->format('Y-m-d');
}
