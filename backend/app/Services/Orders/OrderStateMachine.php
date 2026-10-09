<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusLog;
use LogicException;

/**
 * Nơi duy nhất đổi `orders.status` (ADR-001 §3); mỗi lần đổi ghi `order_status_logs`. Caller PHẢI đang giữ khoá
 * dòng `orders` (và đúng thứ tự khoá chuẩn) — class này không tự khoá.
 *
 *   pending → paid | failed | cancelled     paid → refunded     failed|cancelled → paid (tiền đến muộn)
 */
class OrderStateMachine
{
    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_USER = 'user';

    public const ACTOR_GATEWAY = 'gateway';

    /** US-022: Admin/Quản lý trang duyệt hoặc huỷ đơn `manual`. */
    public const ACTOR_STAFF = 'staff';

    /** @var array<string, list<OrderStatus>> */
    private const ALLOWED = [
        'pending' => [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Cancelled],
        'paid' => [OrderStatus::Refunded],
        'failed' => [OrderStatus::Paid],
        'cancelled' => [OrderStatus::Paid],
        'refunded' => [],
    ];

    /**
     * Ghi dòng log tạo đơn (null → pending). Gọi ngay sau khi INSERT đơn.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordCreated(Order $order, string $actorType, ?int $actorId, array $meta = []): void
    {
        $this->log($order, null, OrderStatus::Pending, null, $actorType, $actorId, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta  không chứa PII/secret
     */
    public function transition(Order $order, OrderStatus $to, ?string $reason, string $actorType, ?int $actorId = null, array $meta = []): void
    {
        $from = $order->status;

        if (! in_array($to, self::ALLOWED[$from->value], true)) {
            throw new LogicException("Không chuyển được đơn từ {$from->value} sang {$to->value}.");
        }

        $order->forceFill(['status' => $to, 'status_reason' => $reason !== null ? mb_substr($reason, 0, 100) : null]);

        match ($to) {
            OrderStatus::Paid => $order->forceFill(['paid_at' => now()]),
            OrderStatus::Cancelled => $order->forceFill(['cancelled_at' => now()]),
            OrderStatus::Refunded => $order->forceFill(['refunded_at' => now()]),
            default => null,
        };

        $order->save();
        $this->log($order, $from, $to, $reason, $actorType, $actorId, $meta);
    }

    /** @param  array<string, mixed>  $meta */
    private function log(Order $order, ?OrderStatus $from, OrderStatus $to, ?string $reason, string $actorType, ?int $actorId, array $meta): void
    {
        OrderStatusLog::query()->insert([
            'order_id' => $order->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason !== null ? mb_substr($reason, 0, 100) : null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
