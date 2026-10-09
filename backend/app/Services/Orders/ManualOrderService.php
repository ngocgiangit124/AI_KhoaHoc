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
use App\Models\OrderNote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\Data\FulfillmentOptions;
use App\Support\VnTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vòng đời đơn thủ công ngoài checkout (US-022, ADR-007, docs/tech/US-022.md): học sinh tự huỷ, hết hạn, và (T39) Quản trị viên
 * duyệt / duyệt muộn / huỷ / ghi chú nội bộ.
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
        private readonly OrderFulfillmentService $fulfillment,
        private readonly AuditLogger $audit,
    ) {}

    /** Các nhóm trường PII mà response chi tiết đơn trả (docs/tech/US-022.md, quy ước PII trong audit). */
    private const PII_FIELDS = ['contact', 'customer_note', 'internal_notes'];

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
        // Tài khoản đã ẩn danh: guard của approve từ chối duyệt muộn → `approval` phản ánh đúng (một nguồn sự thật).
        $canLate = $d['can_approve_late'] && $order->user?->anonymized_at === null;
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
     * Quản trị viên duyệt đơn `manual` (đã nhận đủ tiền) hoặc duyệt muộn đơn đã huỷ trong cửa sổ (`$late = true`). Đi qua
     * `OrderFulfillmentService::markPaid` nguồn `manual`: guard dưới khoá `orders` (`decide()`) chạy TRƯỚC nhánh "đã paid", nên duyệt
     * lần 2 nhận 409. KHÔNG mở transaction ngoài (retry deadlock nằm trong `markPaid`; `guard`/`after` có thể chạy lại).
     *
     * @throws DomainException ORDER_NOT_MANUAL · ALREADY_PROCESSED · ORDER_STATUS_CHANGED · ORDER_APPROVAL_WINDOW_PASSED ·
     *                         COURSE_UNAVAILABLE (đều 409)
     */
    public function approve(Order $order, User $staff, bool $late, ?string $paymentReference, ?string $note): Order
    {
        $from = null;

        $options = new FulfillmentOptions(
            actorType: OrderStateMachine::ACTOR_STAFF,
            actorId: (int) $staff->getKey(),
            statusReason: 'manual_confirmed',
            strictCourses: true,
            attributes: ['confirmed_by' => (int) $staff->getKey()],
            guard: function (Order $locked) use ($late, &$from): void {
                $from = $locked->status->value;
                $d = $this->decide($locked);
                $code = $late ? $d['late_error'] : $d['approve_error'];

                $deletedAccount = false;

                if ($code === null && $late) {
                    // Tài khoản đã ẩn danh (xoá) thì không duyệt muộn. Đọc thường, không khoá `users` (khoá `users` sau `orders` đảo thứ tự
                    // khoá với xoá tài khoản). Connection chạy READ COMMITTED nên đọc này thấy dữ liệu đã commit mới nhất; cửa sổ còn lại chỉ là
                    // từ lúc đọc tới lúc commit của duyệt (xoá tài khoản commit đúng khoảng đó), hậu quả đã chấp nhận (docs/security/T39.md S2).
                    $anonymized = User::query()->whereKey($locked->user_id)->value('anonymized_at');

                    if ($anonymized !== null) {
                        $code = 'ORDER_APPROVAL_WINDOW_PASSED';
                        $deletedAccount = true;
                    }
                }

                if ($code !== null) {
                    throw $this->conflict($code, $locked, $d, $deletedAccount);
                }
            },
            after: function (Order $locked, array $reasons) use ($staff, $late, $paymentReference, $note, &$from): void {
                if ($note !== null) {
                    $this->insertNote($locked, $staff, $note);
                }

                // Không ghi nội dung ghi chú/mã giao dịch. Response là chi tiết đơn (PII đầy đủ) không kèm `order.view_pii` → `pii_fields`.
                $this->audit->log('order.manual_approve', $locked, [
                    'status' => ['from' => $from, 'to' => OrderStatus::Paid->value],
                    'late' => $late,
                    'needs_review' => (bool) $locked->needs_review,
                    'has_reference' => $paymentReference !== null,
                    'pii_fields' => self::PII_FIELDS,
                ]);
            },
        );

        return $this->fulfillment->markPaid($order, 'manual', $paymentReference, $options);
    }

    /**
     * Quản trị viên huỷ đơn `manual` `pending` kèm lý do công khai (hiện cho học sinh + trong thư), ghi chú nội bộ tuỳ chọn.
     *
     * @throws DomainException ORDER_NOT_MANUAL · ALREADY_PROCESSED · ORDER_STATUS_CHANGED (409)
     */
    public function cancelByStaff(Order $order, User $staff, string $publicReason, ?string $note): Order
    {
        return $this->cancel(
            (int) $order->getKey(), 'admin_cancelled', OrderStateMachine::ACTOR_STAFF, (int) $staff->getKey(), onlyIfExpired: false,
            mailVariant: ManualOrderCancelledMail::VARIANT_ADMIN_CANCELLED, publicReason: $publicReason, staff: $staff, note: $note,
        );
    }

    /**
     * Ghi chú nội bộ (append-only) cho đơn ở mọi trạng thái. Chỉ INSERT (FK lấy khoá S trên `orders`): không khoá `carts`, không đổi
     * trạng thái.
     */
    public function addNote(Order $order, User $staff, string $body): OrderNote
    {
        return DB::transaction(function () use ($order, $staff, $body): OrderNote {
            if (! Order::query()->whereKey($order->getKey())->exists()) {
                throw new DomainException('NOT_FOUND', 'Không tìm thấy đơn hàng.', 404);
            }

            $note = $this->insertNote($order, $staff, $body);
            $this->audit->log('order.note_add', $order, ['note_id' => $note->getKey(), 'pii_fields' => ['internal_notes']]);

            return $note;
        }, self::DEADLOCK_ATTEMPTS);
    }

    private function insertNote(Order $order, User $staff, string $body): OrderNote
    {
        $note = new OrderNote;
        $note->forceFill(['order_id' => $order->getKey(), 'author_id' => $staff->getKey(), 'body' => $body, 'created_at' => now()])->save();

        return $note;
    }

    /**
     * @param  array<string, mixed>  $d  kết quả `decide()`
     */
    private function conflict(string $code, Order $locked, array $d, bool $deletedAccount = false): DomainException
    {
        $until = VnTime::iso($d['approval_window_until']);

        return match ($code) {
            'ORDER_NOT_MANUAL' => new DomainException($code, 'Đơn hàng này không phải đơn thanh toán thủ công.', 409),
            'ALREADY_PROCESSED' => new DomainException($code, 'Đơn hàng đã được xử lý trước đó.', 409, ['status' => $locked->status->value, 'status_reason' => $locked->status_reason]),
            'ORDER_APPROVAL_WINDOW_PASSED' => new DomainException($code, $deletedAccount || $locked->status_reason === 'account_deleted' ? 'Tài khoản học sinh đã bị xoá nên không duyệt muộn được.' : 'Đã quá thời hạn duyệt muộn của đơn này.', 409, ['cancelled_at' => VnTime::iso($locked->cancelled_at), 'approval_window_until' => $until]),
            default => new DomainException('ORDER_STATUS_CHANGED', 'Trạng thái đơn hàng vừa thay đổi. Vui lòng tải lại.', 409, [
                'status' => $locked->status->value,
                'status_reason' => $locked->status_reason,
                'cancelled_at' => VnTime::iso($locked->cancelled_at),
                'can_approve_late' => $d['can_approve_late'],
                'approval_window_until' => $until,
            ]),
        };
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
    private function cancel(int $orderId, string $reason, string $actorType, ?int $actorId, bool $onlyIfExpired, ?string $mailVariant, ?string $publicReason = null, ?User $staff = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($orderId, $reason, $actorType, $actorId, $onlyIfExpired, $mailVariant, $publicReason, $staff, $note): Order {
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

                if ($staff !== null) {
                    // admin-api: đủ khoá theo contract §2.5.1 (FE quyết định hiện nút "Duyệt muộn" ngay).
                    $d = $this->decide($locked);
                    $context += [
                        'cancelled_at' => VnTime::iso($locked->cancelled_at),
                        'can_approve_late' => $d['can_approve_late'],
                        'approval_window_until' => VnTime::iso($d['approval_window_until']),
                    ];
                }

                throw $locked->status === OrderStatus::Cancelled
                    ? new DomainException('ALREADY_PROCESSED', 'Đơn hàng đã được huỷ trước đó.', 409, $context)
                    : new DomainException('ORDER_STATUS_CHANGED', 'Trạng thái đơn hàng vừa thay đổi. Vui lòng tải lại.', 409, $context);
            }

            if ($onlyIfExpired && $locked->expires_at->gt(now())) {
                throw new DomainException('ORDER_NOT_EXPIRED', 'Đơn hàng chưa hết hạn.', 409);
            }

            if ($staff !== null) {
                $locked->forceFill(['cancel_reason_public' => $publicReason]);
            }

            $this->states->transition($locked, OrderStatus::Cancelled, $reason, $actorType, $actorId);

            if ($staff !== null) {
                if ($note !== null) {
                    $this->insertNote($locked, $staff, $note);
                }

                // Không ghi lý do/ghi chú. Response là chi tiết đơn (PII đầy đủ) không kèm `order.view_pii` → `pii_fields`.
                $this->audit->log('order.manual_cancel', $locked, [
                    'status' => ['from' => OrderStatus::Pending->value, 'to' => OrderStatus::Cancelled->value],
                    'pii_fields' => self::PII_FIELDS,
                ]);
            }

            if ($mailVariant !== null) {
                // Chỉ truyền id: notifier đọc lại học sinh SAU commit (tài khoản vừa ẩn danh giữa chừng thì không gửi thư).
                DB::afterCommit(fn () => $this->notifier->cancelled($locked, (int) $userId, $mailVariant, $publicReason));
            }

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }
}
