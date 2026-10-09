<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\DomainException;
use App\Mail\ManualOrderCancelledMail;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vòng đời đơn thủ công ngoài checkout (US-022, ADR-007, docs/tech/US-022.md): học sinh tự huỷ, hết hạn. (Duyệt/huỷ bởi quản
 * trị ở T39 thêm vào đây.)
 *
 * Quy tắc khoá (DBA 2026-10-08): mọi luồng ghi đơn đi `carts (PK, nếu có) → orders (PK) → courses → enrollments → coupons`.
 * `cancel` đọc `user_id` bằng đọc thường, lấy id giỏ bằng đọc thường rồi khoá theo PK (không `where('user_id')->lockForUpdate()`
 * trên khoảng có thể rỗng), rồi khoá `orders` và KIỂM LẠI dưới khoá. Mỗi lần gọi là MỘT transaction ngắn (`DB::transaction($fn, 3)`:
 * retry deadlock); thư vào hàng đợi trong `DB::afterCommit` nên rollback/retry không gửi.
 *
 * Nhả chỗ lượt mã là tự động: `CouponCapacity` chỉ đếm đơn `pending`. Giỏ không đổi.
 */
class ManualOrderService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly OrderStateMachine $states,
        private readonly ManualOrderNotifier $notifier,
    ) {}

    /**
     * Học sinh tự huỷ đơn `manual` `pending` của mình (BR14). Không gửi thư, không audit (chỉ `order_status_logs`, actor `user`).
     *
     * @throws DomainException ORDER_NOT_MANUAL 409 · ALREADY_PROCESSED 409 · ORDER_STATUS_CHANGED 409
     */
    public function cancelByStudent(Order $order, User $student): Order
    {
        return $this->cancel((int) $order->getKey(), 'user_cancelled', OrderStateMachine::ACTOR_USER, (int) $student->getKey(), onlyIfExpired: false, mailVariant: null);
    }

    /**
     * Huỷ mọi đơn `manual` `pending` đã quá `expires_at` (lý do `expired`, actor `system`). Đọc thường danh sách id theo
     * `(status, expires_at)`, rồi MỖI ID một transaction, kiểm lại dưới khoá. Đơn đã đổi trạng thái giữa chừng → bỏ qua.
     *
     * @return int số đơn đã huỷ
     */
    public function expireDue(int $limit = 500): int
    {
        $ids = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('payment_method', PaymentMethods::MANUAL)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            try {
                $this->cancel((int) $id, 'expired', OrderStateMachine::ACTOR_SYSTEM, null, onlyIfExpired: true, mailVariant: ManualOrderCancelledMail::VARIANT_EXPIRED);
                $cancelled++;
            } catch (DomainException) {
                // Đơn vừa được duyệt/huỷ/gia hạn bởi luồng khác: không còn việc cho job.
            } catch (Throwable $e) {
                Log::error('orders.expire_manual_failed', ['order_id' => $id, 'exception' => $e::class]);
            }
        }

        return $cancelled;
    }

    /**
     * Lõi huỷ đơn `manual`. Thứ tự kiểm dưới khoá: phương thức → trạng thái → (hết hạn) quá hạn.
     *
     * @param  'expired'|'admin_cancelled'|null  $mailVariant
     */
    private function cancel(int $orderId, string $reason, string $actorType, ?int $actorId, bool $onlyIfExpired, ?string $mailVariant, ?string $publicReason = null): Order
    {
        return DB::transaction(function () use ($orderId, $reason, $actorType, $actorId, $onlyIfExpired, $mailVariant, $publicReason): Order {
            $userId = Order::query()->whereKey($orderId)->value('user_id');

            if ($userId === null) {
                throw new DomainException('NOT_FOUND', 'Không tìm thấy đơn hàng.', 404);
            }

            // carts trước orders (cùng tiền tố với checkout/markPaid); giỏ có thể không tồn tại.
            $cartId = Cart::query()->where('user_id', $userId)->value('id');
            if ($cartId !== null) {
                Cart::query()->whereKey($cartId)->lockForUpdate()->first();
            }

            $locked = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($locked->payment_method !== PaymentMethods::MANUAL) {
                throw new DomainException('ORDER_NOT_MANUAL', 'Đơn hàng này không phải đơn thanh toán thủ công nên không huỷ được ở đây.', 409);
            }

            if ($locked->status !== OrderStatus::Pending) {
                $context = ['status' => $locked->status->value, 'status_reason' => $locked->status_reason];

                throw $locked->status === OrderStatus::Cancelled
                    ? new DomainException('ALREADY_PROCESSED', 'Đơn hàng đã được huỷ trước đó.', 409, $context)
                    : new DomainException('ORDER_STATUS_CHANGED', 'Trạng thái đơn hàng vừa thay đổi. Vui lòng tải lại.', 409, $context);
            }

            if ($onlyIfExpired && $locked->expires_at->gt(now())) {
                throw new DomainException('ORDER_NOT_EXPIRED', 'Đơn hàng chưa hết hạn.', 409);
            }

            $this->states->transition($locked, OrderStatus::Cancelled, $reason, $actorType, $actorId);

            if ($mailVariant !== null) {
                $student = User::query()->find($userId);

                if ($student !== null) {
                    DB::afterCommit(fn () => $this->notifier->cancelled($locked, $student, $mailVariant, $publicReason));
                }
            }

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }
}
