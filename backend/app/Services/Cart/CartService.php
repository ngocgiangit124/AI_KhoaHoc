<?php

namespace App\Services\Cart;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Cart\Data\CartSnapshot;
use App\Services\Cart\Data\CouponEvaluation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Giỏ hàng của học sinh (US-004). Mọi thao tác ghi khoá dòng `carts` của HS (data-model §4: carts → coupons),
 * giỏ chỉ được tạo lazy khi thêm khóa đầu tiên. Mã giảm giá chỉ là THAM CHIẾU (`carts.coupon_id`): không trừ/giữ
 * lượt dùng ở đây (US-004 dev note; giữ chỗ do T18 tạo đơn).
 *
 * Giao diện cho T18 (CheckoutService), gọi TRONG transaction đã khoá `carts`:
 *   `lockCart($user)` → `snapshot($cart, $user)` → items hợp lệ + `unavailableItems` (removed_items) + mã còn hiệu lực
 *   + `pricing` (tính bằng PricingCalculator). Mã hết hiệu lực bị gỡ khỏi giỏ kèm notice `COUPON_REMOVED`.
 */
class CartService
{
    /** Trần 30 lần nhập mã sai/ngày/học sinh (api-contract §1.6, S18). */
    public const COUPON_FAILS_PER_DAY = 30;

    public function __construct(
        private readonly PricingCalculator $pricing,
        private readonly CouponEvaluator $evaluator,
    ) {}

    /** Xem giỏ; tự gỡ mã nếu hết điều kiện (vô hiệu hoá/hết hạn/hết lượt/phạm vi đổi). Không tạo giỏ. */
    public function view(User $user): CartSnapshot
    {
        $cart = Cart::query()->where('user_id', $user->getKey())->first();
        if ($cart === null) {
            return $this->snapshot(null, $user);
        }

        // Đọc không khoá; chỉ mở transaction + khoá giỏ khi mã thật sự hết hiệu lực để gỡ (đánh giá lại dưới khoá).
        $snapshot = $this->snapshot($cart, $user, persist: false);
        if ($cart->coupon_id === null || $snapshot->coupon !== null) {
            return $snapshot;
        }

        return DB::transaction(fn (): CartSnapshot => $this->snapshot($this->lockCart($user), $user));
    }

    /** Số dòng trong giỏ (badge icon giỏ, `/auth/me.cart_count`). */
    public function count(User $user): int
    {
        return CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.user_id', $user->getKey())
            ->count();
    }

    public function contains(User $user, int $courseId): bool
    {
        return CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.user_id', $user->getKey())
            ->where('cart_items.course_id', $courseId)
            ->exists();
    }

    /**
     * @throws ValidationException course_id không tồn tại/chưa xuất bản/đã xoá/miễn phí (422, field `course_id`)
     * @throws DomainException ALREADY_OWNED | ALREADY_IN_CART (409)
     */
    public function addItem(User $user, int $courseId): CartSnapshot
    {
        $this->assertPurchasable(Course::query()->find($courseId));

        // Tạo giỏ lazy, an toàn khi 2 tab cùng tạo (U user_id → insertOrIgnore), rồi khoá dòng giỏ.
        Cart::query()->insertOrIgnore(['user_id' => $user->getKey(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($user, $courseId): CartSnapshot {
            $cart = $this->lockCart($user);

            // Kiểm lại trên dòng mới nhất sau khi giữ khoá giỏ (xoá/ngừng bán xen giữa).
            $this->assertPurchasable(Course::query()->find($courseId));

            if (Enrollment::query()->where('user_id', $user->getKey())->where('course_id', $courseId)
                ->where('status', EnrollmentStatus::Active->value)->exists()) {
                throw new DomainException('ALREADY_OWNED', 'Bạn đã sở hữu khóa học này.', 409);
            }

            // U (cart_id, course_id) là chốt chặn thật; 0 dòng được chèn = đã có.
            try {
                CartItem::query()->insert(['cart_id' => $cart->getKey(), 'course_id' => $courseId, 'created_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('ALREADY_IN_CART', 'Khóa học đã có trong giỏ hàng.', 409);
            }

            return $this->snapshot($cart, $user);
        });
    }

    /** Gỡ khóa khỏi giỏ (idempotent: không có thì vẫn trả giỏ). Mã hết điều kiện bị gỡ kèm notice (AC10). */
    public function removeItem(User $user, int $courseId): CartSnapshot
    {
        if (Cart::query()->where('user_id', $user->getKey())->doesntExist()) {
            return $this->snapshot(null, $user);
        }

        return DB::transaction(function () use ($user, $courseId): CartSnapshot {
            $cart = $this->lockCart($user);
            CartItem::query()->where('cart_id', $cart->getKey())->where('course_id', $courseId)->delete();

            return $this->snapshot($cart, $user);
        });
    }

    /**
     * Áp mã (thay mã cũ — AC9). Thất bại giữ nguyên mã/giá hiện tại. Mỗi lần thất bại tính vào trần 30/ngày.
     *
     * @throws DomainException COUPON_INVALID | COUPON_EXPIRED | COUPON_ALREADY_USED | COUPON_NOT_APPLICABLE (422)
     * @throws ThrottleRequestsException 429 khi đã sai quá 30 lần/ngày
     */
    public function applyCoupon(User $user, string $rawCode): CartSnapshot
    {
        // Đếm nguyên tử: `hit` trước (một bước với kiểm trần), lần áp thành công thì hoàn lại (review T16 M2).
        $key = 'coupon-fail:'.$user->getKey();
        if (RateLimiter::hit($key, 86400) > self::COUPON_FAILS_PER_DAY) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }

        try {
            $snapshot = DB::transaction(function () use ($user, $rawCode): CartSnapshot {
                $cart = Cart::query()->where('user_id', $user->getKey())->exists() ? $this->lockCart($user) : null;

                $coupon = Coupon::query()->where('code', Coupon::normalizeCode($rawCode))->first();
                if ($coupon === null) {
                    throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422);
                }

                if ($cart === null) {
                    throw new DomainException('COUPON_NOT_APPLICABLE', 'Mã không áp dụng được cho giỏ hàng hiện tại.', 422);
                }

                [$items] = $this->classify($cart, $user);
                $this->evaluator->evaluate($coupon, $user, $this->prices($items));

                $cart->forceFill(['coupon_id' => $coupon->getKey()])->save();

                return $this->snapshot($cart, $user);
            });
        } catch (\Throwable $e) {
            // Chỉ lỗi `COUPON_*` là "lần sai"; lỗi khác (hạ tầng) không bị tính.
            if (! ($e instanceof DomainException && str_starts_with($e->code(), 'COUPON_'))) {
                RateLimiter::decrement($key, 86400);
            }

            throw $e;
        }

        RateLimiter::decrement($key, 86400);

        return $snapshot;
    }

    public function removeCoupon(User $user): CartSnapshot
    {
        if (Cart::query()->where('user_id', $user->getKey())->doesntExist()) {
            return $this->snapshot(null, $user);
        }

        return DB::transaction(function () use ($user): CartSnapshot {
            $cart = $this->lockCart($user);
            $cart->forceFill(['coupon_id' => null])->save();

            return $this->snapshot($cart, $user);
        });
    }

    /** Khoá dòng `carts` của HS (phải gọi trong transaction; giỏ phải tồn tại). */
    public function lockCart(User $user): Cart
    {
        return Cart::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Đánh giá lại giỏ. `persist` = gỡ mã hết điều kiện khỏi `carts.coupon_id` (cần đang giữ khoá giỏ).
     */
    public function snapshot(?Cart $cart, User $user, bool $persist = true): CartSnapshot
    {
        if ($cart === null) {
            return new CartSnapshot([], [], null, null, $this->pricing->calculate([]), []);
        }

        [$items, $unavailable] = $this->classify($cart, $user);
        $prices = $this->prices($items);
        $notices = [];

        if ($unavailable !== []) {
            $notices[] = ['code' => 'ITEMS_UNAVAILABLE', 'message' => 'Một số khóa học không còn khả dụng. Hãy xóa chúng khỏi giỏ hàng.'];
        }

        $evaluation = null;
        if ($cart->coupon_id !== null) {
            $evaluation = $this->reevaluate($cart, $user, $prices, $persist, $notices);
        }

        $pricing = $this->pricing->calculate($prices, $evaluation?->coupon, $evaluation?->eligibleCourseIds);

        return new CartSnapshot($items, $unavailable, $evaluation?->coupon, $evaluation, $pricing, $notices);
    }

    /**
     * @param  array<int, int>  $prices
     * @param  list<array{code: string, message: string}>  $notices
     */
    private function reevaluate(Cart $cart, User $user, array $prices, bool $persist, array &$notices): ?CouponEvaluation
    {
        $coupon = Coupon::query()->find($cart->coupon_id);

        try {
            if ($coupon === null) {
                throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422);
            }

            return $this->evaluator->evaluate($coupon, $user, $prices);
        } catch (DomainException $e) {
            if ($persist) {
                $cart->forceFill(['coupon_id' => null])->save();
            }
            $notices[] = ['code' => 'COUPON_REMOVED', 'message' => 'Mã giảm giá đã được gỡ vì không còn áp dụng được: '.mb_strtolower($e->getMessage())];

            return null;
        }
    }

    /**
     * Chia dòng giỏ thành hợp lệ / không khả dụng (khóa đã xoá, ngừng bán, miễn phí, đã sở hữu). Mới thêm trước.
     *
     * @return array{0: list<CartItem>, 1: list<CartItem>}
     */
    private function classify(Cart $cart, User $user): array
    {
        $all = CartItem::query()->where('cart_id', $cart->getKey())->with('course')->orderByDesc('id')->get();

        $owned = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->whereIn('course_id', $all->pluck('course_id'))
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('course_id')
            ->flip();

        $items = [];
        $unavailable = [];
        foreach ($all as $item) {
            $course = $item->course;
            $ok = $course !== null
                && ! $course->trashed()
                && $course->status === CourseStatus::Published
                && $course->price > 0
                && ! $owned->has($item->course_id);
            $ok ? $items[] = $item : $unavailable[] = $item;
        }

        return [$items, $unavailable];
    }

    /**
     * @param  list<CartItem>  $items
     * @return array<int, int>
     */
    private function prices(array $items): array
    {
        $prices = [];
        foreach ($items as $item) {
            $prices[$item->course_id] = (int) $item->course->price;
        }

        return $prices;
    }

    private function assertPurchasable(?Course $course): void
    {
        $reason = match (true) {
            $course === null || $course->trashed() || $course->status !== CourseStatus::Published => 'Khóa học không tồn tại hoặc không còn được bán.',
            $course->price <= 0 => 'Khóa học miễn phí không thêm vào giỏ hàng, hãy đăng ký học.',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['course_id' => [$reason]]);
        }
    }
}
