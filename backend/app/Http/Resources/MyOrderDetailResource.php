<?php

namespace App\Http\Resources;

use App\Enums\CourseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Orders\PaymentMethods;
use App\Support\VnTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết đơn của chính học sinh (api-contract §2.3.1). KHÔNG bao giờ có: ghi chú nội bộ, người duyệt, mã giao dịch,
 * `needs_review`. `cancel_reason` chỉ có khi Quản trị viên huỷ (`admin_cancelled`). Cần `items.course` (kể cả đã xoá mềm) đã eager load.
 *
 * @property Order $resource
 */
class MyOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $isManualPending = $order->payment_method === PaymentMethods::MANUAL && $order->status === OrderStatus::Pending;

        return [
            'code' => $order->code,
            'status' => $order->status->value,
            'status_reason' => $order->status_reason,
            'payment_method' => $order->payment_method,
            'items' => $order->items->sortBy('id')->map(fn ($item) => [
                'course_id' => $item->course_id,
                'title' => $item->course_title,
                // Khóa đã xoá hoặc không còn công khai: slug null để FE không tạo link.
                'slug' => $item->course !== null && ! $item->course->trashed() && $item->course->status === CourseStatus::Published ? $item->course->slug : null,
                'grade_level' => $item->course !== null && ! $item->course->trashed() ? $item->course->grade_level : null,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'final_amount' => $item->final_amount,
            ])->values()->all(),
            'coupon_code' => $order->coupon_code,
            'subtotal' => $order->subtotal_amount,
            'discount' => $order->discount_amount,
            'total' => $order->total_amount,
            'customer_note' => $order->customer_note,
            'cancel_reason' => $order->status_reason === 'admin_cancelled' ? $order->cancel_reason_public : null,
            'replaced_by_code' => $order->status_reason === 'superseded' ? $this->replacedByCode($order) : null,
            'created_at' => VnTime::iso($order->created_at),
            'expires_at' => VnTime::iso($order->expires_at),
            'paid_at' => VnTime::iso($order->paid_at),
            'cancelled_at' => VnTime::iso($order->cancelled_at),
            'refunded_at' => VnTime::iso($order->refunded_at),
            'can_cancel' => $isManualPending,
            'payment' => $this->payment($order),
        ];
    }

    private function replacedByCode(Order $order): ?string
    {
        $code = Order::query()->where('user_id', $order->user_id)->where('id', '>', $order->getKey())->orderBy('id')->value('code');

        return is_string($code) ? $code : null;
    }

    /**
     * `null` với `manual`/`none`. Đơn cổng giữ shape `{gateway, link_expired, can_retry}` (T20 hoàn thiện luồng thử lại).
     *
     * @return array<string, mixed>|null
     */
    private function payment(Order $order): ?array
    {
        $method = $order->payment_method;

        if ($method === null || $method === PaymentMethods::MANUAL || $method === 'none') {
            return null;
        }

        $pending = $order->status === OrderStatus::Pending;
        $liveLink = $pending && PaymentAttempt::query()
            ->where('order_id', $order->getKey())
            ->where('status', PaymentAttemptStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->exists();

        return ['gateway' => $method, 'link_expired' => $pending && ! $liveLink, 'can_retry' => $pending && ! $liveLink];
    }
}
