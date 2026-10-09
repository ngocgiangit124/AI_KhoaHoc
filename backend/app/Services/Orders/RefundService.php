<?php

namespace App\Services\Orders;

use App\Enums\EnrollmentStatus;
use App\Enums\OrderStatus;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn tiền đơn (US-010, api-contract §2.5/§2.5.1; áp cả đơn `manual` đã `paid`, US-022 AC26). Tiền hoàn NGOÀI hệ thống: service chỉ
 * ghi nhận `paid → refunded`, thu hồi quyền học do CHÍNH đơn này cấp (`enrollments.order_id`) và ghi audit `order.refund`.
 *
 * Thứ tự khoá (DBA 2026-10-08): `carts (PK, nếu có) → orders (PK) → courses (tăng id) → enrollments`, cùng chuỗi với `markPaid`.
 * Một transaction ngoài cùng, retry deadlock 3 lần (`DB::transaction($fn, 3)`); `EnrollmentService::revoke` lồng bên trong (savepoint).
 * Quyền học có từ đơn khác/nguồn khác (mua trùng, `already_owned`) KHÔNG bị thu hồi.
 */
class RefundService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly OrderStateMachine $states,
        private readonly EnrollmentService $enrollments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws DomainException ALREADY_PROCESSED 409 (đã hoàn) · ORDER_STATUS_CHANGED 409 (chưa `paid`) · NOT_FOUND 404
     */
    public function refund(Order $order, User $staff, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $staff, $note): Order {
            // carts trước orders; `user_id` bất biến nên lấy từ `$order` đã nạp (không truy vấn thêm). Đọc thường lấy id giỏ rồi khoá theo PK
            // (không khoá khoảng rỗng khi HS chưa có giỏ). Đơn không còn → `firstOrFail()` bên dưới → 404.
            $cartId = Cart::query()->where('user_id', $order->user_id)->value('id');
            if ($cartId !== null) {
                Cart::query()->whereKey($cartId)->lockForUpdate()->first();
            }

            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Refunded) {
                throw new DomainException('ALREADY_PROCESSED', 'Đơn hàng này đã được hoàn tiền.', 409, ['status' => $locked->status->value, 'status_reason' => $locked->status_reason]);
            }

            if ($locked->status !== OrderStatus::Paid) {
                throw new DomainException('ORDER_STATUS_CHANGED', 'Chỉ hoàn tiền được đơn đã thanh toán. Vui lòng tải lại.', 409, ['status' => $locked->status->value, 'status_reason' => $locked->status_reason]);
            }

            // Tăng dần theo course_id: `revoke` khoá courses rồi enrollments, nên thứ tự lặp phải khớp thứ tự khoá chuẩn.
            $ids = Enrollment::query()
                ->where('order_id', $locked->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->orderBy('course_id')
                ->pluck('id');

            $revoked = 0;
            foreach ($ids as $id) {
                try {
                    $this->enrollments->revoke(Enrollment::query()->findOrFail($id), 'refund', $staff);
                    $revoked++;
                } catch (DomainException $e) {
                    if ($e->code() !== 'ALREADY_PROCESSED') {
                        throw $e; // đã bị thu hồi bởi luồng khác giữa chừng thì bỏ qua
                    }
                }
            }

            $locked->forceFill(['refunded_by' => $staff->getKey(), 'refund_note' => $note]);
            $this->states->transition($locked, OrderStatus::Refunded, 'refunded', OrderStateMachine::ACTOR_STAFF, (int) $staff->getKey(), ['source' => 'refund']);

            // Không ghi nội dung ghi chú vào audit (có thể chứa thông tin khách). `pii_fields`: response của refund là chi tiết đơn có PII
            // nhưng không ghi `order.view_pii` → quy ước (docs/tech/US-022.md): truy vấn PII = `order.view_pii` HOẶC `changes.pii_fields`.
            $this->audit->log('order.refund', $locked, [
                'total' => $locked->total_amount, 'enrollments_revoked' => $revoked, 'has_note' => $note !== null,
                'pii_fields' => ['contact', 'customer_note', 'internal_notes'],
            ]);

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }
}
