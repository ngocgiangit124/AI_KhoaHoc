<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\PiiMasker;
use App\Support\VnTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết đơn quản trị (api-contract §2.5.1): email/SĐT ĐẦY ĐỦ (route ghi audit `order.view_pii`), không có thông tin phụ huynh.
 * Dựng qua `AdminOrderQuery::detailResource()`; `$approval` là kết quả `ManualOrderService::approvalState()`, `$names` là
 * bản đồ id → tên của người duyệt/hoàn tiền/viết ghi chú/thao tác trạng thái (một truy vấn).
 *
 * @property Order $resource
 */
class AdminOrderDetailResource extends JsonResource
{
    /** Khoá `meta` của `order_status_logs` được phép trả (docs/tech/US-022.md). */
    private const META_ALLOWLIST = ['source', 'late', 'review', 'items'];

    /** Lý do `needs_review` được phép trả (api-contract §2.5.1). */
    private const REVIEW_REASONS = ['late_payment', 'already_owned', 'coupon_over_limit', 'coupon_already_used', 'course_unavailable'];

    /**
     * @param  array<string, mixed>  $approval
     * @param  array<int, string>  $names
     */
    public function __construct(Order $resource, private readonly array $approval, private readonly array $names)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'code' => $order->code,
            'status' => $order->status->value,
            'status_reason' => $order->status_reason,
            'payment_method' => $order->payment_method,
            'needs_review' => $order->needs_review,
            'needs_review_reasons' => $this->reviewReasons($order),
            'subtotal' => $order->subtotal_amount,
            'discount' => $order->discount_amount,
            'total' => $order->total_amount,
            'coupon_code' => $order->coupon_code,
            'payment_reference' => $order->payment_reference,
            'customer_note' => $order->customer_note,
            'cancel_reason' => $order->cancel_reason_public,
            'created_at' => VnTime::iso($order->created_at),
            'expires_at' => VnTime::iso($order->expires_at),
            'paid_at' => VnTime::iso($order->paid_at),
            'cancelled_at' => VnTime::iso($order->cancelled_at),
            'refunded_at' => VnTime::iso($order->refunded_at),
            'refund_note' => $order->refund_note,
            'confirmed_by' => $this->person($order->confirmed_by),
            'refunded_by' => $this->person($order->refunded_by),
            'student' => PiiMasker::full($order->user, (int) $order->user_id),
            'items' => $order->items->map(function ($item): array {
                $course = $item->course;
                $deleted = $course === null || $course->trashed();

                return [
                    'course_id' => (int) $item->course_id,
                    'title' => $item->course_title,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'final_amount' => $item->final_amount,
                    'course_status' => $deleted ? 'deleted' : $course->status->value,
                    'current_price' => $deleted ? null : $course->price,
                ];
            })->values()->all(),
            'status_logs' => $order->statusLogs->map(fn ($log): array => [
                'from' => $log->from_status,
                'to' => $log->to_status,
                'reason' => $log->reason,
                'actor_type' => $log->actor_type,
                'actor' => in_array($log->actor_type, ['user', 'staff'], true) ? $this->person($log->actor_id) : null,
                'meta' => array_intersect_key((array) $log->meta, array_flip(self::META_ALLOWLIST)) ?: null,
                'created_at' => VnTime::iso($log->created_at),
            ])->values()->all(),
            'notes' => $order->notes->map(fn ($note): array => [
                'id' => $note->getKey(),
                'body' => $note->body,
                'author' => $this->person($note->author_id),
                'created_at' => VnTime::iso($note->created_at),
            ])->values()->all(),
            // Chỉ đơn cổng mới có; KHÔNG trả pay_url / create_response (link thanh toán, dữ liệu thô của cổng).
            'attempts' => $order->attempts->map(fn ($a): array => [
                'id' => $a->getKey(),
                'gateway' => $a->gateway,
                'gateway_order_id' => $a->gateway_order_id,
                'amount' => $a->amount,
                'status' => $a->status->value,
                'result_code' => $a->result_code,
                'result_message' => $a->result_message,
                'expires_at' => VnTime::iso($a->expires_at),
                'created_at' => VnTime::iso($a->created_at),
            ])->values()->all(),
            'approval' => [
                'can_approve' => $this->approval['can_approve'],
                'can_approve_late' => $this->approval['can_approve_late'],
                'can_cancel' => $this->approval['can_cancel'],
                'approval_window_until' => VnTime::iso($this->approval['approval_window_until']),
                'warnings' => $this->approval['warnings'],
                'late_approval_warnings' => $this->approval['late_approval_warnings'],
            ],
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function person(?int $id): ?array
    {
        return $id !== null && isset($this->names[$id]) ? ['id' => $id, 'name' => $this->names[$id]] : null;
    }

    /**
     * Lý do của lần chuyển sang `paid` GẦN NHẤT (`meta.review`); `[]` khi chưa thanh toán hoặc không có.
     *
     * @return list<string>
     */
    private function reviewReasons(Order $order): array
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Refunded], true)) {
            return [];
        }

        $paid = $order->statusLogs->where('to_status', OrderStatus::Paid->value)->sortBy('id')->last();
        $review = is_array($paid?->meta) ? ($paid->meta['review'] ?? []) : [];

        return array_values(array_intersect(self::REVIEW_REASONS, (array) $review));
    }
}
