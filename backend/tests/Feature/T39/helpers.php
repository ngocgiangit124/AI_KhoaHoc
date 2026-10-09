<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T24/helpers.php';

/** POST admin-api sau `vvStaffLogin`. */
function vvT39Post(Order|string $order, string $action, array $payload = [])
{
    $code = $order instanceof Order ? $order->code : $order;

    return vvT24Post("/admin/orders/{$code}/{$action}", $payload);
}

/** Duyệt hợp lệ (confirm=true). */
function vvT39Approve(Order $order, array $extra = [])
{
    return vvT39Post($order, 'approve', array_merge(['confirm' => true], $extra));
}

/** Đơn manual đã huỷ cách đây `$daysAgo` ngày với lý do `$reason` (có dòng đơn thật). */
function vvT39Cancelled(string $reason = 'expired', float|int $daysAgo = 1, ?User $student = null, int $items = 1): Order
{
    return vvT24Order($student, ['cancelled_at' => now()->subDays($daysAgo), 'status_reason' => $reason], 'cancelled', $items);
}

/** Số dòng DB phản ánh đơn: để chứng minh "nhánh lỗi không đổi gì". */
function vvT39Snapshot(Order $order): array
{
    return [
        'order' => (array) DB::table('orders')->where('id', $order->id)->first(),
        'logs' => DB::table('order_status_logs')->where('order_id', $order->id)->count(),
        'notes' => DB::table('order_notes')->where('order_id', $order->id)->count(),
        'enrollments' => DB::table('enrollments')->where('user_id', $order->user_id)->count(),
        'usages' => DB::table('coupon_usages')->where('order_id', $order->id)->count(),
        'audit' => DB::table('audit_logs')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->count(),
    ];
}
