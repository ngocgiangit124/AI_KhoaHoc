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
use App\Services\Orders\Data\FulfillmentOptions;
use App\Services\Privacy\ParentNotifier;
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
        private readonly ParentNotifier $parentNotifier,
        private readonly ManualOrderNotifier $mailer,
    ) {}

    /**
     * @param  string  $source  `checkout` (đơn 0đ) | `ipn` | `query` | `manual` (Quản trị viên duyệt, US-022) — ghi vào log trạng thái
     * @param  FulfillmentOptions|null  $options  null = hành vi cũ (checkout/ipn/query). `manual` dùng `guard`/`after`/`strictCourses`.
     *
     * @throws DomainException ALREADY_PROCESSED 409 nếu đơn đã hoàn tiền; mã khác do `guard`/`strictCourses` ném
     */
    public function markPaid(Order $order, string $source, ?string $paymentReference = null, ?FulfillmentOptions $options = null): Order
    {
        // grantPurchase tự retry deadlock chỉ khi là transaction ngoài cùng, nên retry ở mức này (T14 ghi chú).
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Order => $this->apply($order, $source, $paymentReference, $options));
            } catch (QueryException $e) {
                if ($attempt >= self::DEADLOCK_ATTEMPTS || ! in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                    throw $e;
                }
            }
        }
    }

    private function apply(Order $order, string $source, ?string $paymentReference, ?FulfillmentOptions $options): Order
    {
        // carts trước orders (chuỗi khoá chuẩn); giỏ có thể không tồn tại (HS chưa từng có giỏ) → bỏ qua.
        // Đọc thường lấy id rồi khoá theo PK: không khoá khoảng rỗng (gap lock) khi HS không có giỏ.
        $cartId = Cart::query()->where('user_id', $order->user_id)->value('id');
        $cart = $cartId === null ? null : Cart::query()->whereKey($cartId)->lockForUpdate()->first();
        $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        // Guard dưới khoá, TRƯỚC nhánh "đã paid → trả về": duyệt lần 2 nhận 409 thay vì 200 im lặng.
        if ($options?->guard !== null) {
            ($options->guard)($locked);
        }

        if ($locked->status === OrderStatus::Paid) {
            return $locked;
        }

        if ($locked->status === OrderStatus::Refunded) {
            throw new DomainException('ALREADY_PROCESSED', 'Đơn hàng này đã được hoàn tiền.', 409);
        }

        $late = $locked->status !== OrderStatus::Pending;
        $needsReview = $late; // tiền đến muộn
        $reasons = $needsReview ? ['late_payment'] : [];

        $student = User::query()->findOrFail($locked->user_id);
        $items = $locked->items()->with('course')->orderBy('course_id')->get();

        $unavailable = [];

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

                if ($options?->strictCourses === true) {
                    $unavailable[] = ['id' => (int) $item->course_id, 'title' => (string) $item->course_title];

                    continue;
                }

                $needsReview = true;
                $reasons[] = 'course_unavailable';
                Log::channel('payments')->critical('Không cấp được quyền học: khóa đã xoá.', ['order' => $locked->code, 'course_id' => $item->course_id]);
            }
        }

        if ($unavailable !== []) {
            // Rollback toàn bộ (không duyệt một phần): enrollment đã cấp ở vòng trước cũng bị huỷ cùng transaction.
            throw new DomainException('COURSE_UNAVAILABLE', 'Có khóa học trong đơn đã bị xoá nên không duyệt được đơn.', 409, ['courses' => $unavailable]);
        }

        if ($locked->coupon_id !== null) {
            $couponReasons = $this->recordCouponUsage($locked);
            $needsReview = $couponReasons !== [] || $needsReview;
            $reasons = [...$reasons, ...$couponReasons];
        }

        if ($paymentReference !== null) {
            $locked->forceFill(['payment_reference' => mb_substr($paymentReference, 0, 100)]);
        }

        $locked->forceFill(['needs_review' => $locked->needs_review || $needsReview]);
        if ($options !== null && $options->attributes !== []) {
            $locked->forceFill($options->attributes);
        }

        $reviewList = array_values(array_unique($reasons));
        $manual = $source === 'manual';
        $this->states->transition(
            $locked,
            OrderStatus::Paid,
            $options->statusReason ?? ($source === 'checkout' ? 'zero_amount' : null),
            $options->actorType ?? ($source === 'checkout' ? OrderStateMachine::ACTOR_USER : OrderStateMachine::ACTOR_GATEWAY),
            $options !== null ? $options->actorId : ($source === 'checkout' ? $locked->user_id : null),
            ['source' => $source] + ($manual ? ['late' => $late] : []) + ($reviewList === [] ? [] : ['review' => $reviewList]),
        );

        if ($options?->after !== null) {
            ($options->after)($locked, $reviewList);
        }

        // ADR-006 (T29): đơn CÓ TIỀN vừa chuyển paid → thông báo phụ huynh sau commit. Chỉ chạy ở lần chuyển trạng thái này
        // (đơn đã paid thoát sớm ở trên) nên IPN trùng không gửi lại. Đơn 0đ/miễn phí không gửi. Lỗi gửi được nuốt trong notifier.
        if ($locked->total_amount > 0) {
            DB::afterCommit(fn () => $this->parentNotifier->orderPaid($locked));
        }

        // Thư xác nhận cho học sinh (đơn CÓ TIỀN; đơn 0đ `checkout` không gửi). Cùng điều kiện "chỉ ở lần chuyển trạng thái này".
        if ($locked->total_amount > 0 && $source !== 'checkout') {
            DB::afterCommit(fn () => $this->mailer->orderPaid($locked, $source));
        }

        // Dọn giỏ: bỏ các khóa đã mua, gỡ mã đã dùng (US-005 BR3).
        if ($cart !== null) {
            CartItem::query()->where('cart_id', $cart->getKey())->whereIn('course_id', $items->pluck('course_id'))->delete();
            if ($locked->coupon_id !== null && (int) $cart->coupon_id === (int) $locked->coupon_id) {
                $cart->forceFill(['coupon_id' => null])->save();
            }
        }

        return $locked;
    }

    /**
     * @return list<string> lý do cần `needs_review` (rỗng = không): `coupon_already_used` (HS đã dùng mã này ở đơn khác, vi phạm unique
     *                      lượt dùng; không tăng `used_count`), `coupon_over_limit` (vượt `max_uses`), `coupon_missing` (không xảy ra: FK restrict)
     */
    private function recordCouponUsage(Order $order): array
    {
        $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();
        if ($coupon === null) {
            return ['coupon_missing'];
        }

        $usage = new CouponUsage;
        $usage->forceFill(['coupon_id' => $coupon->getKey(), 'user_id' => $order->user_id, 'order_id' => $order->getKey(), 'used_at' => now()]);

        try {
            $usage->save();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['coupon_already_used']; // HS đã dùng mã này (đơn khác) hoặc đơn đã ghi lượt: không tăng used_count
            }

            throw $e;
        }

        Coupon::query()->whereKey($coupon->getKey())->increment('used_count');

        return $coupon->max_uses !== null && $coupon->used_count + 1 > $coupon->max_uses ? ['coupon_over_limit'] : [];
    }
}
