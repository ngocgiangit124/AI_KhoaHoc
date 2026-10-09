<?php

namespace App\Services\Privacy;

use App\Enums\EnrollmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pha B của xoá tài khoản (ADR-006 §6, tasks.md T34.4). IDEMPOTENT: chạy lại không lỗi, không ghi audit/đổi gì thêm.
 * Mỗi bước là một transaction riêng, đi đúng đoạn con của thứ tự khoá chuẩn (carts → orders → courses → enrollments).
 * Khóa đang học (enrollment `active`) giữ nguyên.
 */
class AccountDeletionFinalizer
{
    /** Attempt `created` mới hơn ngưỡng này coi là đang được request khác gọi cổng (cùng ngưỡng CheckoutService). */
    private const IN_FLIGHT_SECONDS = 60;

    public const WITHDRAW_REASON = 'Học sinh đã xoá tài khoản';

    public function __construct(
        private readonly OrderStateMachine $states,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * Chỉ xử lý tài khoản ĐÃ ẩn danh hoá (không bao giờ dọn dữ liệu của tài khoản còn sống).
     */
    /**
     * @return bool `false` nếu còn đơn pending có link sống/đang tạo bị bỏ qua (job phải chạy lại sau)
     */
    public function finalize(int $userId): bool
    {
        $anonymized = User::query()->whereKey($userId)->whereNotNull('anonymized_at')->exists();

        if (! $anonymized) {
            return true;
        }

        $this->clearCart($userId);
        $complete = $this->cancelPendingOrder($userId);
        $this->withdrawPendingEnrollments($userId);

        return $complete;
    }

    private function clearCart(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $cart = Cart::query()->where('user_id', $userId)->lockForUpdate()->first();

            if ($cart === null) {
                return;
            }

            CartItem::query()->where('cart_id', $cart->getKey())->delete();

            if ($cart->coupon_id !== null) {
                $cart->forceFill(['coupon_id' => null])->save();
            }
        });
    }

    private function cancelPendingOrder(int $userId): bool
    {
        $kept = false;

        DB::transaction(function () use ($userId, &$kept): void {
            // carts (khoá tuần tự hoá checkout) → orders (theo PK, kiểm lại trạng thái).
            Cart::query()->where('user_id', $userId)->lockForUpdate()->first();

            // US-022 (security S1): `customer_note` có thể chứa SĐT/Zalo bên thứ ba → xoá trên MỌI đơn của HS (đơn giữ làm chứng từ).
            // Ở pha B để đúng thứ tự khoá carts → orders (pha A giữ `users` X, khoá `orders` ở đó có thể vòng chờ với markPaid). Từng đơn khoá theo PK.
            // T38-2: cùng lúc xoá nội dung nhân viên tự nhập trên đơn đó (có thể nhắc SĐT/tên HS): refund_note, payment_reference,
            // cancel_reason_public và order_notes.body (NOT NULL → chuỗi cố định; query builder có chủ đích, model OrderNote vẫn append-only).
            $noteIds = Order::query()->where('user_id', $userId)->where(fn ($q) => $q
                ->whereNotNull('customer_note')->orWhereNotNull('refund_note')->orWhereNotNull('payment_reference')->orWhereNotNull('cancel_reason_public'))
                ->orderBy('id')->pluck('id');
            foreach ($noteIds as $noteId) {
                Order::query()->whereKey($noteId)->lockForUpdate()->first();
                Order::query()->whereKey($noteId)->update(['customer_note' => null, 'refund_note' => null, 'payment_reference' => null, 'cancel_reason_public' => null]);
            }
            DB::table('order_notes')
                ->whereIn('order_id', Order::query()->where('user_id', $userId)->select('id'))
                ->where('body', '<>', OrderNote::PURGED_BODY)
                ->update(['body' => OrderNote::PURGED_BODY]);

            $ids = Order::query()->where('user_id', $userId)->where('status', OrderStatus::Pending->value)->orderBy('id')->pluck('id');

            foreach ($ids as $id) {
                $order = Order::query()->whereKey($id)->lockForUpdate()->first();

                if ($order === null || $order->status !== OrderStatus::Pending) {
                    continue;
                }

                if ($order->payment_method === 'manual') {
                    // US-022: đơn thủ công chờ duyệt (đua với pha A hoặc đã quá hạn nhưng job chưa chạy) → GIỮ nguyên. KHÔNG đặt
                    // `$kept` (không phát lại job): `orders:expire-manual` sẽ huỷ khi hết hạn (không gửi thư cho tài khoản đã ẩn danh).
                    Log::info('account_deletion.manual_order_kept', ['user_id' => $userId, 'order_id' => $order->getKey()]);

                    continue;
                }

                if ($this->hasLiveLink($order)) {
                    // Race hiếm (link vừa được tạo sau pha A): để job huỷ đơn quá hạn (T20) dọn.
                    Log::info('account_deletion.pending_order_kept', ['user_id' => $userId, 'order_id' => $order->getKey()]);
                    $kept = true;

                    continue;
                }

                $this->states->transition($order, OrderStatus::Cancelled, 'account_deleted', OrderStateMachine::ACTOR_SYSTEM);
            }
        });

        return ! $kept;
    }

    private function hasLiveLink(Order $order): bool
    {
        $now = now();

        return PaymentAttempt::query()
            ->where('order_id', $order->getKey())
            ->where(function ($q) use ($now): void {
                $q->where(fn ($p) => $p->where('status', PaymentAttemptStatus::Pending->value)->where('expires_at', '>', $now))
                    ->orWhere(fn ($c) => $c->where('status', PaymentAttemptStatus::Created->value)->where('created_at', '>', $now->copy()->subSeconds(self::IN_FLIGHT_SECONDS)));
            })
            ->exists();
    }

    private function withdrawPendingEnrollments(int $userId): void
    {
        Enrollment::query()
            ->where('user_id', $userId)
            ->where('status', EnrollmentStatus::PendingApproval->value)
            ->orderBy('id')
            ->get()
            ->each(fn (Enrollment $e) => $this->enrollments->withdrawPending($e, self::WITHDRAW_REASON));
    }
}
