<?php

namespace App\Services\Orders;

use App\Enums\CourseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponEvaluator;
use App\Services\Cart\Data\CartSnapshot;
use App\Services\Cart\Data\Pricing;
use App\Services\Cart\PricingCalculator;
use App\Services\Orders\Data\CheckoutResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Exceptions\AmountOutOfRangeException;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checkout (US-005, ADR-001 §4/§6/§8). Hai pha:
 *
 *  1. Một transaction chốt mọi thứ cần khoá: tạo/dùng lại đơn `pending` + `order_items` + `payment_attempts(created)`.
 *  2. Gọi cổng thanh toán NGOÀI transaction (không giữ khoá trong lúc gọi mạng), rồi ghi `pay_url` vào attempt.
 *     Đơn 0đ không có attempt: sau pha 1 gọi `OrderFulfillmentService::markPaid` (transaction riêng, đúng thứ tự khoá).
 *
 * Thứ tự khoá (chuỗi chuẩn của luồng tiền — CHÚ Ý khác bản chữ của data-model §4: `courses` đứng TRƯỚC `coupons`
 * vì `EnrollmentService::grantPurchase` khoá `courses` trước `enrollments`, nên fulfillment đi
 * `carts → orders → courses → enrollments → coupons`; checkout đi `carts → orders → courses(S) → coupons`.
 * Đảo thứ tự này sẽ gây deadlock checkout ↔ IPN):
 *   carts (FOR UPDATE) → orders (đơn pending cũ, theo PK) → courses (FOR SHARE, tăng dần theo id) → coupons (FOR UPDATE).
 * `courses` được khoá SHARE để `CourseService::delete`/`unpublish` (FOR UPDATE) không chen giữa lúc chốt đơn.
 *
 * Quyết định:
 *  - Giỏ/giá/mã đổi so với `expected_total` HS thấy → commit phần đã gỡ mã (nếu có) rồi 409 CHECKOUT_CHANGED + preview mới.
 *  - Mã hết chỗ (tính cả đơn pending còn hạn giữ chỗ của HS khác) → gỡ mã khỏi giỏ + 409 CHECKOUT_CHANGED (ADR-001 §6).
 *  - Đã có đơn pending: cùng nội dung (khóa, số tiền từng dòng, mã, cổng) → dùng lại (không tạo đơn mới); khác nội
 *    dung hoặc đã quá `expires_at` → huỷ (`superseded`), tạo đơn mới.
 *  - Không bao giờ tạo link mới khi còn attempt chưa được cổng xác nhận (tránh trả tiền 2 lần): link còn hạn → trả
 *    lại; link hết hạn chưa xác nhận → `linkExpired` (HS gọi POST /orders/{code}/pay của T20 để đối soát).
 */
class CheckoutService
{
    private const DEADLOCK_ATTEMPTS = 3;

    /** Attempt `created` mới hơn ngưỡng này coi là đang được request khác gọi cổng (timeout cổng 10s + kết nối 5s). */
    private const IN_FLIGHT_SECONDS = 45;

    public function __construct(
        private readonly CartService $cart,
        private readonly PricingCalculator $pricing,
        private readonly CouponEvaluator $evaluator,
        private readonly CouponCapacity $capacity,
        private readonly OrderStateMachine $states,
        private readonly OrderCodeGenerator $codes,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * Xem trước đơn (không ghi DB, không khoá): giỏ đã đánh giá lại + sức chứa mã (đơn pending của HS khác giữ chỗ).
     * Mã hết chỗ chỉ bị ẩn khỏi preview (checkout mới là nơi gỡ mã khỏi giỏ).
     */
    public function preview(User $user): CartSnapshot
    {
        $cart = Cart::query()->where('user_id', $user->getKey())->first();
        $snapshot = $this->cart->snapshot($cart, $user, persist: false);

        if ($snapshot->coupon === null) {
            return $snapshot;
        }

        $ownPendingId = Order::query()->where('user_id', $user->getKey())->where('status', OrderStatus::Pending->value)->value('id');

        if ($this->capacity->hasRoom($snapshot->coupon, $ownPendingId !== null ? (int) $ownPendingId : null)) {
            return $snapshot;
        }

        return $this->withoutCoupon($snapshot, [['code' => 'COUPON_EXHAUSTED', 'message' => 'Mã giảm giá đã hết lượt sử dụng nên không được áp dụng.']]);
    }

    /**
     * @throws DomainException CART_EMPTY 422 · ZERO_TOTAL_DISABLED 422 · AMOUNT_BELOW_GATEWAY_MIN/ABOVE_GATEWAY_MAX 422 ·
     *                         PAYMENT_IN_PROGRESS 409 · PAYMENT_GATEWAY_UNAVAILABLE 502 (đơn vẫn pending, `errors.order_code`) ·
     *                         CheckoutChangedException 409
     * @throws ValidationException cổng không nằm trong `enabled_gateways` (chỉ khi tổng > 0, sau 409/503)
     */
    public function checkout(User $user, int $expectedTotal, string $gateway): CheckoutResult
    {
        // Cụm 3 M1: cổng chỉ được kiểm khi đơn cần thanh toán (tổng > 0, trong prepare()); đơn 0đ không cần gateway
        // nên vẫn chạy khi PAYMENT_GATEWAYS rỗng (mẫu production V1).
        $gateway = mb_strtolower($gateway);

        if (Cart::query()->where('user_id', $user->getKey())->doesntExist()) {
            throw $this->cartEmpty();
        }

        $plan = DB::transaction(fn (): array => $this->prepare($user, $expectedTotal, $gateway), self::DEADLOCK_ATTEMPTS);

        if ($plan['changed'] !== null) {
            $snapshot = $this->preview($user);
            $notices = array_values(array_filter(array_map(fn (string $r) => $this->noticeFor($r), $plan['changed'])));

            throw new CheckoutChangedException(
                new CartSnapshot($snapshot->items, $snapshot->unavailableItems, $snapshot->coupon, $snapshot->evaluation, $snapshot->pricing, [...$snapshot->notices, ...$notices]),
                $plan['changed'],
            );
        }

        /** @var Order $order */
        $order = $plan['order'];

        if ($order->total_amount === 0) {
            // Đơn 0đ: không qua cổng. Hoàn tất ở transaction riêng (carts → orders → courses → enrollments → coupons).
            return new CheckoutResult($this->fulfillment->markPaid($order, 'checkout'), null, $plan['reused']);
        }

        /** @var PaymentAttempt|null $attempt */
        $attempt = $plan['attempt'];

        if ($attempt !== null && $plan['attempt_new']) {
            $attempt = $this->initiatePayment($order, $attempt);
        }

        return new CheckoutResult($order, $attempt, $plan['reused'], $plan['link_expired']);
    }

    /**
     * Pha 1. Trả `changed` (danh sách lý do) khi cần báo CHECKOUT_CHANGED sau commit; ngược lại trả đơn/attempt.
     *
     * @return array{changed: list<string>|null, order: ?Order, attempt: ?PaymentAttempt, attempt_new: bool, reused: bool, link_expired: bool}
     */
    private function prepare(User $user, int $expectedTotal, string $gateway): array
    {
        $now = now();
        $cart = $this->cart->lockCart($user);

        // T34: tài khoản vừa bị ẩn danh hoá (đua với xoá tài khoản) không tạo được đơn. Khoá SHARE `users` sau `carts`
        // (pha A giữ `users` X rồi không đụng `carts`; pha B chỉ khoá `carts` → `orders`) nên không tạo vòng chờ.
        $anonymizedAt = User::query()->whereKey($user->getKey())->sharedLock()->value('anonymized_at');
        if ($anonymizedAt !== null) {
            throw new DomainException('SESSION_REVOKED', 'Tài khoản đã được xoá.', 401);
        }

        $existing = $this->lockPendingOrder($user);

        $snapshot = $this->cart->snapshot($cart, $user);
        if ($snapshot->items === []) {
            throw $this->cartEmpty();
        }

        $prices = [];
        foreach ($snapshot->items as $item) {
            $prices[$item->course_id] = (int) $item->course->price;
        }

        // courses (SHARE, tăng dần theo id): kiểm lại trên dòng mới nhất; xoá/ngừng bán/đổi giá xen giữa → báo đổi.
        $courses = Course::withTrashed()->whereIn('id', array_keys($prices))->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($prices as $courseId => $price) {
            $course = $courses->get($courseId);
            if ($course === null || $course->trashed() || $course->status !== CourseStatus::Published || $course->price !== $price) {
                return $this->changed(['ITEMS_CHANGED']);
            }
        }

        $reasons = [];
        $coupon = $snapshot->coupon;
        $eligible = $snapshot->evaluation?->eligibleCourseIds;

        if (collect($snapshot->notices)->contains('code', 'COUPON_REMOVED')) {
            $reasons[] = 'COUPON_REMOVED'; // snapshot đã gỡ mã khỏi giỏ (hết hạn/vô hiệu/phạm vi đổi)
        }

        if ($coupon !== null) {
            $coupon = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->first();

            try {
                if ($coupon === null) {
                    throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422);
                }
                $eligible = $this->evaluator->evaluate($coupon, $user, $prices, $now)->eligibleCourseIds;
            } catch (DomainException) {
                $reasons[] = 'COUPON_REMOVED';
                $coupon = null;
                $eligible = null;
                $cart->forceFill(['coupon_id' => null])->save();
            }

            if ($coupon !== null && ! $this->capacity->hasRoom($coupon, $existing?->getKey(), $now)) {
                $reasons[] = 'COUPON_EXHAUSTED';
                $coupon = null;
                $eligible = null;
                $cart->forceFill(['coupon_id' => null])->save();
            }
        }

        $pricing = $this->pricing->calculate($prices, $coupon, $eligible);

        if ($pricing->total !== $expectedTotal) {
            return $this->changed($reasons === [] ? ['PRICE_CHANGED'] : $reasons);
        }

        if ($pricing->total > 0 && ! config('features.paid_checkout')) {
            throw new DomainException('PAYMENT_DISABLED', 'Thanh toán trực tuyến đang tạm khoá.', 503);
        }

        if ($pricing->total > 0 && ! in_array($gateway, $this->gateways->enabled(), true)) {
            throw ValidationException::withMessages(['gateway' => ['Phương thức thanh toán không được hỗ trợ.']]);
        }

        $this->assertPayable($pricing->total, $gateway);

        $method = $pricing->total === 0 ? 'none' : $gateway;
        $lines = [];
        foreach ($pricing->lines as $line) {
            $lines[$line->courseId] = $line->finalAmount;
        }
        ksort($lines);

        $reused = false;
        if ($existing !== null) {
            $existingLines = $existing->items()->pluck('final_amount', 'course_id')->map(fn ($v) => (int) $v)->all();
            ksort($existingLines);

            $same = $existing->expires_at->gt($now)
                && $existing->payment_method === $method
                && (int) $existing->coupon_id === (int) $coupon?->getKey()
                && $existing->total_amount === $pricing->total
                && $existingLines === $lines;

            if ($same) {
                $order = $existing;
                $reused = true;
                if ($coupon !== null && ($order->coupon_hold_until === null || $order->coupon_hold_until->lte($now))) {
                    $order->forceFill(['coupon_hold_until' => $this->holdUntil($method, $now, $order->expires_at)])->save();
                }
            } else {
                $this->states->transition($existing, OrderStatus::Cancelled, 'superseded', OrderStateMachine::ACTOR_USER, $user->getKey());
            }
        }

        if (! $reused) {
            $order = $this->createOrder($user, $pricing, $coupon, $courses, $method, $now);
        }

        if ($pricing->total === 0) {
            return ['changed' => null, 'order' => $order, 'attempt' => null, 'attempt_new' => false, 'reused' => $reused, 'link_expired' => false];
        }

        [$attempt, $isNew, $linkExpired] = $this->resolveAttempt($order, $gateway, $now);

        return ['changed' => null, 'order' => $order, 'attempt' => $attempt, 'attempt_new' => $isNew, 'reused' => $reused, 'link_expired' => $linkExpired];
    }

    /**
     * Quyết định dùng lại link / tạo attempt mới dưới khoá `orders`. Chỉ tạo attempt mới khi KHÔNG còn attempt nào
     * chưa được cổng xác nhận (`created` đang bay, `pending`): tránh HS trả tiền cho 2 link của cùng một đơn.
     *
     * @return array{0: ?PaymentAttempt, 1: bool, 2: bool} [attempt, mới tạo?, link hết hạn chưa xác nhận?]
     */
    private function resolveAttempt(Order $order, string $gateway, Carbon $now): array
    {
        $attempts = PaymentAttempt::query()->where('order_id', $order->getKey())->orderByDesc('id')->get();

        $active = $attempts->first(fn (PaymentAttempt $a) => $a->status === PaymentAttemptStatus::Pending && $a->pay_url !== null && $a->expires_at?->gt($now));
        if ($active !== null) {
            return [$active, false, false];
        }

        foreach ($attempts as $a) {
            if ($a->status !== PaymentAttemptStatus::Created) {
                continue;
            }
            if ($a->created_at->gt($now->copy()->subSeconds(self::IN_FLIGHT_SECONDS))) {
                throw new DomainException('PAYMENT_IN_PROGRESS', 'Giao dịch thanh toán đang được tạo. Vui lòng thử lại sau vài giây.', 409);
            }
            // Request tạo link đã chết giữa chừng: đánh dấu lỗi để không kẹt đơn mãi.
            $a->forceFill(['status' => PaymentAttemptStatus::Error, 'result_message' => 'stale_created'])->save();
        }

        if ($attempts->contains(fn (PaymentAttempt $a) => $a->status === PaymentAttemptStatus::Pending)) {
            return [null, false, true];
        }

        $linkTtl = (int) config("payments.gateways.{$gateway}.link_ttl_minutes", 30);
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'order_id' => $order->getKey(),
            'gateway' => $gateway,
            'gateway_order_id' => $order->code.'-'.($attempts->count() + 1),
            'request_id' => (string) Str::uuid(),
            'amount' => $order->total_amount,
            'status' => PaymentAttemptStatus::Created,
            'expires_at' => $this->min($now->copy()->addMinutes($linkTtl), $order->expires_at),
        ])->save();

        return [$attempt, true, false];
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private function createOrder(User $user, Pricing $pricing, ?Coupon $coupon, Collection $courses, string $method, Carbon $now): Order
    {
        $expiresAt = $now->copy()->addHours((int) config('orders.pending_ttl_hours', 12));

        $order = new Order;
        $order->forceFill([
            'code' => $this->codes->generate(),
            'user_id' => $user->getKey(),
            'status' => OrderStatus::Pending,
            'subtotal_amount' => $pricing->subtotal,
            'discount_amount' => $pricing->discount,
            'total_amount' => $pricing->total,
            'coupon_id' => $coupon?->getKey(),
            'coupon_code' => $coupon?->code,
            'coupon_hold_until' => $coupon !== null ? $this->holdUntil($method, $now, $expiresAt) : null,
            'payment_method' => $method,
            'needs_review' => false,
            'expires_at' => $expiresAt,
        ])->save();

        OrderItem::query()->insert(array_map(fn ($line) => [
            'order_id' => $order->getKey(),
            'course_id' => $line->courseId,
            'course_title' => mb_substr((string) $courses->get($line->courseId)->title, 0, 255),
            'unit_price' => $line->unitPrice,
            'discount_amount' => $line->discountAmount,
            'final_amount' => $line->finalAmount,
        ], $pricing->lines));

        $this->states->recordCreated($order, OrderStateMachine::ACTOR_USER, $user->getKey(), ['items' => count($pricing->lines)]);

        return $order;
    }

    /** Hạn giữ chỗ lượt mã = min(hạn link thanh toán, `payments.coupon_hold_minutes`, hạn đơn) (ADR-001 §6, S18). */
    private function holdUntil(string $method, Carbon $now, Carbon $orderExpiresAt): Carbon
    {
        $linkTtl = (int) config("payments.gateways.{$method}.link_ttl_minutes", 30);
        $minutes = min($linkTtl, (int) config('payments.coupon_hold_minutes', 30));

        return $this->min($now->copy()->addMinutes($minutes), $orderExpiresAt);
    }

    private function min(Carbon $a, Carbon $b): Carbon
    {
        return $a->lt($b) ? $a : $b;
    }

    /** Pha 2: gọi cổng ngoài transaction, rồi ghi kết quả vào attempt (compare-and-set theo `created`). */
    private function initiatePayment(Order $order, PaymentAttempt $attempt): PaymentAttempt
    {
        try {
            $init = $this->gateways->driver($attempt->gateway)->createPayment(new PaymentRequest(
                gatewayOrderId: $attempt->gateway_order_id,
                requestId: $attempt->request_id,
                amount: $attempt->amount,
                description: 'Thanh toan don hang '.$order->code, // không PII (ADR-001 §2)
                returnUrl: $this->returnUrl($order),
                notifyUrl: $this->notifyUrl($attempt->gateway),
                expiresAt: $attempt->expires_at,
            ));
        } catch (GatewayUnavailableException $e) {
            $this->markError($attempt, $e->reason);
            Log::channel('payments')->warning('Tạo giao dịch thất bại.', ['order' => $order->code, 'gateway' => $attempt->gateway, 'reason' => $e->reason]);

            throw new DomainException('PAYMENT_GATEWAY_UNAVAILABLE', 'Không kết nối được cổng thanh toán. Đơn hàng của bạn vẫn được giữ, vui lòng thử lại sau ít phút.', 502, ['order_code' => $order->code]);
        } catch (AmountOutOfRangeException $e) {
            $this->markError($attempt, $e->code());

            throw $e;
        } catch (Throwable $e) {
            $this->markError($attempt, 'unexpected:'.class_basename($e));

            throw $e;
        }

        PaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', PaymentAttemptStatus::Created->value)
            ->update([
                'status' => PaymentAttemptStatus::Pending->value,
                'pay_url' => $init->payUrl,
                'expires_at' => Carbon::instance($init->expiresAt),
                'create_response' => json_encode($init->rawResponse, JSON_THROW_ON_ERROR),
                'next_check_at' => now()->addMinutes(3),
                'updated_at' => now(),
            ]);

        return PaymentAttempt::query()->findOrFail($attempt->getKey());
    }

    private function markError(PaymentAttempt $attempt, string $reason): void
    {
        PaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', PaymentAttemptStatus::Created->value)
            ->update(['status' => PaymentAttemptStatus::Error->value, 'result_message' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    private function returnUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/checkout/ket-qua?order='.$order->code;
    }

    private function notifyUrl(string $gateway): string
    {
        $configured = config("payments.gateways.{$gateway}.ipn_url");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.config('app.api_host').'/api/v1/webhooks/payments/'.$gateway;
    }

    /** Hạn mức cổng + cờ đơn 0đ (ADR-001 §8). Kiểm TRƯỚC khi tạo đơn để không để lại đơn không thể thanh toán. */
    private function assertPayable(int $total, string $gateway): void
    {
        if ($total === 0) {
            if (! config('features.zero_total_checkout')) {
                throw new DomainException('ZERO_TOTAL_DISABLED', 'Đơn hàng 0đ hiện chưa được hỗ trợ. Hãy bỏ mã giảm giá để tiếp tục.', 422);
            }

            return;
        }

        $min = config("payments.gateways.{$gateway}.min_amount");
        $max = config("payments.gateways.{$gateway}.max_amount");

        if (is_int($min) && $total < $min) {
            throw AmountOutOfRangeException::below($min);
        }
        if (is_int($max) && $total > $max) {
            throw AmountOutOfRangeException::above($max);
        }
    }

    /** Đơn pending của HS: đọc thường rồi khoá theo PK (không dùng FOR UPDATE cho truy vấn "có thể không có dòng"). */
    private function lockPendingOrder(User $user): ?Order
    {
        $id = Order::query()->where('user_id', $user->getKey())->where('status', OrderStatus::Pending->value)->value('id');

        if ($id === null) {
            return null;
        }

        $order = Order::query()->whereKey($id)->lockForUpdate()->first();

        return $order !== null && $order->status === OrderStatus::Pending ? $order : null;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{changed: list<string>|null, order: ?Order, attempt: ?PaymentAttempt, attempt_new: bool, reused: bool, link_expired: bool}
     */
    private function changed(array $reasons): array
    {
        return ['changed' => $reasons, 'order' => null, 'attempt' => null, 'attempt_new' => false, 'reused' => false, 'link_expired' => false];
    }

    /** @return array{code: string, message: string}|null */
    private function noticeFor(string $reason): ?array
    {
        return match ($reason) {
            'COUPON_EXHAUSTED' => ['code' => 'COUPON_EXHAUSTED', 'message' => 'Mã giảm giá đã hết lượt sử dụng nên được gỡ khỏi đơn hàng.'],
            'PRICE_CHANGED' => ['code' => 'PRICE_CHANGED', 'message' => 'Tổng tiền đã thay đổi so với lần bạn xem.'],
            'ITEMS_CHANGED' => ['code' => 'ITEMS_CHANGED', 'message' => 'Một số khóa học trong giỏ vừa thay đổi (giá hoặc trạng thái bán).'],
            default => null, // COUPON_REMOVED đã có trong notices của giỏ
        };
    }

    /**
     * @param  list<array{code: string, message: string}>  $extraNotices
     */
    private function withoutCoupon(CartSnapshot $snapshot, array $extraNotices): CartSnapshot
    {
        $prices = [];
        foreach ($snapshot->items as $item) {
            $prices[$item->course_id] = (int) $item->course->price;
        }

        return new CartSnapshot($snapshot->items, $snapshot->unavailableItems, null, null, $this->pricing->calculate($prices), [...$snapshot->notices, ...$extraNotices]);
    }

    private function cartEmpty(): DomainException
    {
        return new DomainException('CART_EMPTY', 'Giỏ hàng trống hoặc không còn khóa học hợp lệ để thanh toán.', 422);
    }
}
