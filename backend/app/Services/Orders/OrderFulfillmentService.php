<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hoàn tất đơn đã thanh toán: đường DUY NHẤT tạo enrollment/`coupon_usages` từ đơn (api-contract §3).
 *
 * T18 chỉ gọi từ đơn 0đ (`source = checkout`). T19 (IPN/đối soát) gọi cùng hàm này với nguồn `ipn`/`query` và bổ sung:
 * verify chữ ký/số tiền, attempt `succeeded`, email `OrderPaid` (afterCommit), retry deadlock đã có ở đây.
 *
 * Thứ tự khoá (transaction riêng, KHÔNG gọi trong transaction đang giữ khoá khác):
 *   carts → orders → courses (tăng dần id; do `EnrollmentService::grantPurchase`) → enrollments → coupons.
 * Idempotent: đơn đã `paid` → trả đơn, không làm lại. Tiền đến muộn (`failed`/`cancelled` → `paid`) bật `needs_review`.
 */
class OrderFulfillmentService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly OrderStateMachine $states,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @param  string  $source  `checkout` (đơn 0đ) | `ipn` | `query` — ghi vào log trạng thái
     *
     * @throws DomainException ALREADY_PROCESSED 409 nếu đơn đã hoàn tiền
     */
    public function markPaid(Order $order, string $source, ?string $paymentReference = null): Order
    {
        // grantPurchase tự retry deadlock chỉ khi là transaction ngoài cùng, nên retry ở mức này (T14 ghi chú).
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Order => $this->apply($order, $source, $paymentReference));
            } catch (QueryException $e) {
                if ($attempt >= self::DEADLOCK_ATTEMPTS || ! in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                    throw $e;
                }
            }
        }
    }

    private function apply(Order $order, string $source, ?string $paymentReference): Order
    {
        // carts trước orders (chuỗi khoá chuẩn); giỏ có thể không tồn tại (HS chưa từng có giỏ) → bỏ qua.
        // Đọc thường lấy id rồi khoá theo PK: không khoá khoảng rỗng (gap lock) khi HS không có giỏ.
        $cartId = Cart::query()->where('user_id', $order->user_id)->value('id');
        $cart = $cartId === null ? null : Cart::query()->whereKey($cartId)->lockForUpdate()->first();
        $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status === OrderStatus::Paid) {
            return $locked;
        }

        if ($locked->status === OrderStatus::Refunded) {
            throw new DomainException('ALREADY_PROCESSED', 'Đơn hàng này đã được hoàn tiền.', 409);
        }

        $needsReview = $locked->status !== OrderStatus::Pending; // tiền đến muộn
        $reasons = $needsReview ? ['late_payment'] : [];

        $student = User::query()->findOrFail($locked->user_id);
        $items = $locked->items()->with('course')->orderBy('course_id')->get();

        foreach ($items as $item) {
            try {
                $enrollment = $this->enrollments->grantPurchase($student, $item->course, $locked->getKey());

                if ((int) $enrollment->order_id !== (int) $locked->getKey()) {
                    // Đã sở hữu khóa từ đơn khác/nguồn khác (mua trùng): giữ quyền cũ, admin xử lý hoàn tiền phần trùng.
                    $needsReview = true;
                    $reasons[] = 'already_owned';
                }
            } catch (DomainException $e) {
                if ($e->code() !== 'COURSE_UNAVAILABLE') {
                    throw $e;
                }
                $needsReview = true;
                $reasons[] = 'course_unavailable';
                Log::channel('payments')->critical('Không cấp được quyền học: khóa đã xoá.', ['order' => $locked->code, 'course_id' => $item->course_id]);
            }
        }

        if ($locked->coupon_id !== null) {
            $needsReview = $this->recordCouponUsage($locked) || $needsReview;
        }

        if ($paymentReference !== null) {
            $locked->forceFill(['payment_reference' => mb_substr($paymentReference, 0, 100)]);
        }

        $locked->forceFill(['needs_review' => $locked->needs_review || $needsReview]);
        $this->states->transition(
            $locked,
            OrderStatus::Paid,
            $source === 'checkout' ? 'zero_amount' : null,
            $source === 'checkout' ? OrderStateMachine::ACTOR_USER : OrderStateMachine::ACTOR_GATEWAY,
            $source === 'checkout' ? $locked->user_id : null,
            ['source' => $source] + ($reasons === [] ? [] : ['review' => array_values(array_unique($reasons))]),
        );

        // Dọn giỏ: bỏ các khóa đã mua, gỡ mã đã dùng (US-005 BR3).
        if ($cart !== null) {
            CartItem::query()->where('cart_id', $cart->getKey())->whereIn('course_id', $items->pluck('course_id'))->delete();
            if ($locked->coupon_id !== null && (int) $cart->coupon_id === (int) $locked->coupon_id) {
                $cart->forceFill(['coupon_id' => null])->save();
            }
        }

        return $locked;
    }

    /** @return bool true nếu cần `needs_review` (vượt max_uses hoặc vi phạm unique lượt dùng) */
    private function recordCouponUsage(Order $order): bool
    {
        $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();
        if ($coupon === null) {
            return true;
        }

        $usage = new CouponUsage;
        $usage->forceFill(['coupon_id' => $coupon->getKey(), 'user_id' => $order->user_id, 'order_id' => $order->getKey(), 'used_at' => now()]);

        try {
            $usage->save();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return true; // HS đã dùng mã này (đơn khác) hoặc đơn đã ghi lượt: không tăng used_count
            }

            throw $e;
        }

        Coupon::query()->whereKey($coupon->getKey())->increment('used_count');

        return $coupon->max_uses !== null && $coupon->used_count + 1 > $coupon->max_uses;
    }
}
