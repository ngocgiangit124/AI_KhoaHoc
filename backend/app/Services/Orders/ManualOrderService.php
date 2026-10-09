<?php

namespace App\Services\Orders;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Mail\ManualOrderCancelledMail;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
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
     * Quyết định THUẦN (không I/O): chỉ dựa vào các cột của hàng `orders` đã nạp/khoá, config và `now()`. T39 gọi hàm này dưới khoá làm
     * guard (không chạm bảng khác); `approvalState()` gọi nó rồi mới tính cảnh báo (có I/O).
     *
     * - `can_approve` = `manual` VÀ `pending`; `can_cancel` cùng điều kiện.
     * - `can_approve_late` = `manual` VÀ `cancelled` VÀ `status_reason ≠ account_deleted` VÀ `now ≤ cancelled_at + approval_window_days`
     *   (`approval_window_days = 0` tắt duyệt muộn, khi đó `approval_window_until = null`).
     * - `late_denied` (khi `can_approve_late = false`): `not_manual` | `not_cancelled` | `account_deleted` | `disabled` | `window_expired`.
     * - Mã 409 theo bảng thứ tự kiểm §2.5.1 (null = được phép): `approve_error` cho duyệt thường (`late=false`), `late_error` cho duyệt
     *   muộn (`late=true`), `cancel_error` cho huỷ. `ORDER_NOT_MANUAL` → `ALREADY_PROCESSED` (paid/refunded; huỷ: đã cancelled) →
     *   `ORDER_STATUS_CHANGED` → `ORDER_APPROVAL_WINDOW_PASSED` (chỉ duyệt muộn).
     *
     * @return array{can_approve: bool, can_approve_late: bool, can_cancel: bool, approval_window_until: Carbon|null, late_denied: string|null, approve_error: string|null, late_error: string|null, cancel_error: string|null}
     */
    public function decide(Order $locked): array
    {
        $manual = $locked->payment_method === PaymentMethods::MANUAL;
        $status = $locked->status;
        $pending = $manual && $status === OrderStatus::Pending;
        $cancelled = $manual && $status === OrderStatus::Cancelled && $locked->cancelled_at !== null;

        $windowDays = max(0, (int) config('orders.manual.approval_window_days'));
        $windowUntil = $cancelled && $windowDays > 0 ? $locked->cancelled_at->copy()->addDays($windowDays) : null;

        $lateDenied = match (true) {
            ! $manual => 'not_manual',
            $status !== OrderStatus::Cancelled => 'not_cancelled',
            $locked->status_reason === 'account_deleted' => 'account_deleted',
            $windowDays === 0 => 'disabled',
            $windowUntil === null || now()->gt($windowUntil) => 'window_expired',
            default => null,
        };

        $common = match (true) {
            ! $manual => 'ORDER_NOT_MANUAL',
            in_array($status, [OrderStatus::Paid, OrderStatus::Refunded], true) => 'ALREADY_PROCESSED',
            default => null,
        };

        return [
            'can_approve' => $pending,
            'can_approve_late' => $lateDenied === null,
            'can_cancel' => $pending,
            'approval_window_until' => $windowUntil,
            'late_denied' => $lateDenied,
            'approve_error' => $common ?? ($status === OrderStatus::Pending ? null : 'ORDER_STATUS_CHANGED'),
            'late_error' => $common ?? match (true) {
                $status !== OrderStatus::Cancelled => 'ORDER_STATUS_CHANGED',
                $lateDenied !== null => 'ORDER_APPROVAL_WINDOW_PASSED',
                default => null,
            },
            'cancel_error' => ! $manual ? 'ORDER_NOT_MANUAL' : match ($status) {
                OrderStatus::Pending => null,
                OrderStatus::Cancelled => 'ALREADY_PROCESSED',
                default => 'ORDER_STATUS_CHANGED',
            },
        ];
    }

    /**
     * `approval{}` của chi tiết quản trị (api-contract §2.5.1) = `decide()` + cảnh báo. Chỉ ĐỌC, không khoá: với đơn đã nạp quan hệ
     * `user`, `items.course` thì tốn 1 truy vấn `enrollments` (+ tối đa 2 truy vấn mã giảm giá khi duyệt muộn được). KHÔNG dùng làm
     * guard dưới khoá (dùng `decide()`).
     *
     * - `warnings` chỉ tính cho đơn `manual` đang `pending`/`cancelled`; đơn khác `[]`.
     * - `late_approval_warnings` chỉ khác `[]` khi `can_approve_late`: dự báo cờ `needs_review` nếu duyệt muộn NGAY LÚC NÀY. Phản chiếu
     *   `OrderFulfillmentService::recordCouponUsage`: đã dùng mã ở đơn khác → chỉ `COUPON_ALREADY_USED`, ngược lại `used_count ≥ max_uses`
     *   → `COUPON_OVER_LIMIT`.
     *
     * @return array{can_approve: bool, can_approve_late: bool, can_cancel: bool, approval_window_until: Carbon|null, warnings: list<array<string, mixed>>, late_approval_warnings: list<array<string, mixed>>}
     */
    public function approvalState(Order $order): array
    {
        $order->loadMissing(['user:id,status,anonymized_at', 'items.course']);

        $d = $this->decide($order);
        $manual = $order->payment_method === PaymentMethods::MANUAL;
        $pending = $d['can_approve'];
        $cancelled = $manual && $order->status === OrderStatus::Cancelled;
        $canLate = $d['can_approve_late'];
        $windowUntil = $d['approval_window_until'];

        $warnings = [];
        $lateWarnings = [];

        if ($pending || $cancelled) {
            $student = $order->user;

            if ($student?->anonymized_at !== null) {
                $warnings[] = ['code' => 'ACCOUNT_DELETED'];
            }

            if ($student?->status === UserStatus::Locked) {
                $warnings[] = ['code' => 'ACCOUNT_LOCKED'];
            }

            $courseIds = $order->items->pluck('course_id')->map(fn ($id) => (int) $id)->all();
            $owned = $courseIds === [] ? [] : Enrollment::query()
                ->where('user_id', $order->user_id)
                ->whereIn('course_id', $courseIds)
                ->where('status', EnrollmentStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('order_id')->orWhere('order_id', '!=', $order->getKey()))
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($order->items->sortBy('id') as $item) {
                $course = $item->course;
                $ref = ['course_id' => (int) $item->course_id, 'title' => $item->course_title];

                if ($course === null || $course->trashed()) {
                    $warnings[] = ['code' => 'COURSE_DELETED'] + $ref;
                } elseif ($course->status !== CourseStatus::Published) {
                    $warnings[] = ['code' => 'COURSE_UNPUBLISHED'] + $ref;
                }

                if (in_array((int) $item->course_id, $owned, true)) {
                    $warnings[] = ['code' => 'ALREADY_OWNED'] + $ref;

                    if ($canLate && $course !== null && ! $course->trashed()) {
                        $lateWarnings[] = ['code' => 'ALREADY_OWNED'] + $ref;
                    }
                }
            }

            if ($canLate && $order->coupon_id !== null) {
                $usedElsewhere = CouponUsage::query()
                    ->where('coupon_id', $order->coupon_id)
                    ->where('user_id', $order->user_id)
                    ->where('order_id', '!=', $order->getKey())
                    ->exists();

                if ($usedElsewhere) {
                    $lateWarnings[] = ['code' => 'COUPON_ALREADY_USED', 'coupon_code' => $order->coupon_code];
                } else {
                    $coupon = Coupon::query()->select(['id', 'used_count', 'max_uses'])->find($order->coupon_id);

                    if ($coupon !== null && $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
                        $lateWarnings[] = ['code' => 'COUPON_OVER_LIMIT', 'coupon_code' => $order->coupon_code];
                    }
                }
            }
        }

        return [
            'can_approve' => $pending,
            'can_approve_late' => $canLate,
            'can_cancel' => $pending,
            'approval_window_until' => $windowUntil,
            'warnings' => $warnings,
            'late_approval_warnings' => $lateWarnings,
        ];
    }

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
