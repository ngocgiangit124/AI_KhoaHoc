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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Giỏ hàng của học sinh (US-004, data-model §3.5, §6).
 *
 * MỌI thao tác luôn đi từ `$user` (người đã đăng nhập) → giỏ của CHÍNH họ:
 * không nhận `cart_id`/`user_id` từ client nên không có IDOR ở giỏ (ID khóa
 * học trong URL/body chỉ dùng để chọn khóa, không chọn giỏ).
 *
 * Khoá: `carts` (theo PK, dòng chắc chắn tồn tại — tạo trước, ngoài
 * transaction) là mutex tuần tự hoá mọi thao tác giỏ/checkout của 1 học sinh;
 * chỉ đi 1 đoạn con của thứ tự chuẩn `carts → orders → coupons` (ở đây chỉ
 * `carts`, `coupons` chỉ đọc không khoá). Mã giảm giá KHÔNG giữ chỗ lượt dùng
 * ở giỏ (US-004 ghi chú Dev).
 */
class CartService
{
    private const NOTICE_PRICING_LIMIT = 'PRICING_LIMIT';

    public function __construct(
        private readonly CouponEvaluator $evaluator,
        private readonly PricingCalculator $pricing,
        private readonly CouponAttemptLimiter $limiter,
    ) {}

    /**
     * Số dòng trong giỏ (badge `cart_count` ở `/auth/me`) — 1 truy vấn, không
     * tạo giỏ.
     */
    public function count(User $user): int
    {
        return CartItem::query()
            ->whereIn('cart_id', Cart::query()->where('user_id', $user->id)->select('id'))
            ->count();
    }

    public function contains(User $user, Course $course): bool
    {
        return CartItem::query()
            ->where('course_id', $course->id)
            ->whereIn('cart_id', Cart::query()->where('user_id', $user->id)->select('id'))
            ->exists();
    }

    /**
     * GET /cart. Tự gỡ mã không còn hợp lệ (mã bị vô hiệu/hết hạn/hết lượt,
     * hoặc giỏ đổi khiến hết phạm vi — US-004 AC10 + "mã bị vô hiệu lúc đang
     * áp"), kèm `notices`.
     */
    public function view(User $user): CartView
    {
        $view = $this->build($this->findCart($user), $user);

        if ($view->cart === null || $view->cart->coupon_id === null) {
            return $view;
        }

        // Mã còn nằm trong giỏ nhưng không dùng được nữa → gỡ (dưới khoá giỏ).
        if ($view->coupon === null) {
            return $this->detachCoupon($view->cart, $user);
        }

        return $view;
    }

    /**
     * POST /cart/items. `$course` đã qua FormRequest (published, price > 0).
     *
     * @throws DomainException `ALREADY_OWNED`, `ENROLLMENT_PENDING`, `ALREADY_IN_CART` (409)
     */
    public function add(User $user, Course $course): CartView
    {
        $live = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('live_flag')
            ->first(['id', 'status']);

        if ($live?->status === EnrollmentStatus::Active) {
            throw new DomainException('ALREADY_OWNED', 'Bạn đã sở hữu khóa học này.', 409);
        }

        if ($live !== null) {
            throw new DomainException('ENROLLMENT_PENDING', 'Yêu cầu đăng ký khóa học này đang chờ duyệt.', 409);
        }

        $cart = $this->ensureCart($user);

        DB::transaction(function () use ($cart, $course): void {
            Cart::query()->lockForUpdate()->findOrFail($cart->id);

            // insertOrIgnore + unique (cart_id, course_id): 2 tab cùng thêm →
            // đúng 1 dòng, request còn lại nhận 409 (không 500).
            $inserted = DB::table('cart_items')->insertOrIgnore([
                'cart_id' => $cart->id,
                'course_id' => $course->id,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                throw new DomainException('ALREADY_IN_CART', 'Khóa học đã có trong giỏ hàng.', 409);
            }
        }, 3);

        return $this->view($user);
    }

    /**
     * DELETE /cart/items/{courseId}. Mã không còn đủ điều kiện được `view()`
     * tự gỡ kèm thông báo.
     *
     * @throws DomainException `NOT_FOUND` (404) khi khóa không nằm trong giỏ của
     *                         người dùng (đồng nhất cho id không tồn tại/nháp/của người khác)
     */
    public function remove(User $user, int $courseId): CartView
    {
        $cart = $this->findCart($user);

        $deleted = 0;

        if ($cart !== null) {
            $deleted = DB::transaction(function () use ($cart, $courseId): int {
                Cart::query()->lockForUpdate()->findOrFail($cart->id);

                return CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->where('course_id', $courseId)
                    ->delete();
            }, 3);
        }

        if ($deleted === 0) {
            throw new DomainException('NOT_FOUND', 'Khóa học không có trong giỏ hàng.', 404);
        }

        return $this->view($user);
    }

    /**
     * PUT /cart/coupon — 1 mã / giỏ, mã mới thay mã cũ (AC9). Lần SAI bị đếm
     * cho limiter 30/ngày (S18).
     *
     * @throws DomainException `COUPON_INVALID|COUPON_ALREADY_USED|COUPON_NOT_APPLICABLE` (422), `TOO_MANY_ATTEMPTS` (429)
     */
    public function applyCoupon(User $user, string $code, ?string $ip): CartView
    {
        $cart = $this->ensureCart($user);

        $this->limiter->attempt($user, $ip, function () use ($cart, $user, $code): void {
            DB::transaction(function () use ($cart, $user, $code): void {
                $locked = Cart::query()->lockForUpdate()->findOrFail($cart->id);

                $built = $this->build($locked, $user, ignoreAppliedCoupon: true);

                $coupon = $this->evaluator->resolveOrFail($code, $user, $built->purchasableCourseIds());

                $locked->coupon_id = $coupon->id;
                $locked->save();

                // Giỏ quá lớn để tính giảm giá an toàn → không áp mã (rollback).
                $priced = $this->build($locked, $user);

                if (collect($priced->notices)->contains('code', self::NOTICE_PRICING_LIMIT)) {
                    throw new DomainException('CART_TOO_LARGE', 'Giỏ hàng quá lớn để áp dụng mã giảm giá.', 422);
                }
            }, 3);
        });

        return $this->view($user);
    }

    /**
     * DELETE /cart/coupon. Idempotent.
     */
    public function removeCoupon(User $user): CartView
    {
        $cart = $this->findCart($user);

        if ($cart !== null && $cart->coupon_id !== null) {
            DB::transaction(function () use ($cart): void {
                $locked = Cart::query()->lockForUpdate()->findOrFail($cart->id);
                $locked->coupon_id = null;
                $locked->save();
            }, 3);
        }

        return $this->view($user);
    }

    private function findCart(User $user): ?Cart
    {
        return Cart::query()->where('user_id', $user->id)->first();
    }

    /**
     * Tạo giỏ lazy NGOÀI transaction (chỉ khoá dòng chắc chắn tồn tại — data-model
     * §6). 2 request đầu tiên cùng tạo: unique `user_id` chặn, bên thua đọc lại.
     */
    private function ensureCart(User $user): Cart
    {
        $cart = $this->findCart($user);

        if ($cart !== null) {
            return $cart;
        }

        try {
            return Cart::query()->create(['user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            return Cart::query()->where('user_id', $user->id)->firstOrFail();
        }
    }

    /**
     * Gỡ mã khỏi giỏ dưới khoá giỏ. Kiểm lại SAU khi khoá: request song song
     * có thể vừa áp 1 mã khác hợp lệ (không gỡ nhầm mã mới).
     */
    private function detachCoupon(Cart $cart, User $user): CartView
    {
        return DB::transaction(function () use ($cart, $user): CartView {
            $locked = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $rebuilt = $this->build($locked, $user);

            if ($locked->coupon_id === null || $rebuilt->coupon !== null) {
                return $rebuilt;
            }

            $locked->coupon_id = null;
            $locked->save();

            $notices = $rebuilt->notices;
            $notices[] = [
                'code' => 'COUPON_REMOVED',
                'message' => 'Mã giảm giá đã được gỡ vì không còn hợp lệ hoặc không còn áp dụng cho giỏ hàng.',
            ];

            return new CartView($rebuilt->cart, $rebuilt->items, $rebuilt->unavailableCourseIds, null, $rebuilt->pricing, $notices);
        }, 3);
    }

    /**
     * Dựng `CartView`: nạp giỏ + khóa (eager), tính khóa không mua được, kiểm
     * mã đang áp, tính giá. Số truy vấn cố định (không N+1) kể cả giỏ > 20 khóa.
     *
     * `$ignoreAppliedCoupon`: dùng khi ÁP MÃ MỚI — chỉ cần danh sách khóa mua được.
     */
    private function build(?Cart $cart, User $user, bool $ignoreAppliedCoupon = false): CartView
    {
        if ($cart === null) {
            return new CartView(null, [], [], null, $this->pricing->calculate([]), []);
        }

        $items = $cart->items()->with('course')->orderBy('id')->get()->all();

        $courseIds = array_map(fn (CartItem $i) => $i->course_id, $items);

        $liveEnrollmentCourseIds = $courseIds === [] ? [] : Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('live_flag')
            ->pluck('course_id')
            ->all();

        $unavailable = [];
        $notices = [];
        $purchasable = [];

        foreach ($items as $item) {
            $course = $item->course;

            $owned = in_array($item->course_id, $liveEnrollmentCourseIds, true);
            $sellable = ! $course->trashed()
                && $course->status === CourseStatus::Published
                && $course->price > 0;

            if ($owned || ! $sellable) {
                $unavailable[] = $item->course_id;
                $notices[] = [
                    'code' => $owned ? 'COURSE_ALREADY_OWNED' : 'COURSE_UNAVAILABLE',
                    'message' => $owned
                        ? 'Bạn đã sở hữu hoặc đang chờ duyệt khóa học này, vui lòng xóa khỏi giỏ hàng.'
                        : 'Khóa học không còn khả dụng, vui lòng xóa khỏi giỏ hàng.',
                    'course_id' => $item->course_id,
                ];

                continue;
            }

            $purchasable[] = $item;
        }

        $purchasableIds = array_map(fn (CartItem $i) => $i->course_id, $purchasable);

        $coupon = null;
        $eligibleIds = [];

        if (! $ignoreAppliedCoupon && $cart->coupon_id !== null) {
            $candidate = Coupon::query()->find($cart->coupon_id);

            if ($candidate !== null && $this->evaluator->rejection($candidate, $user, $purchasableIds) === null) {
                $coupon = $candidate;
                $eligibleIds = $this->evaluator->eligibleCourseIds($candidate, $purchasableIds);
            }
        }

        $lines = array_map(
            fn (CartItem $i) => new PricingLine($i->course_id, (int) $i->course->price, in_array($i->course_id, $eligibleIds, true)),
            $purchasable,
        );

        try {
            $pricing = $this->pricing->calculate($lines, $coupon?->discount_type, $coupon?->discount_value);
        } catch (InvalidArgumentException) {
            // Giỏ quá lớn để tính giảm giá an toàn (vượt giới hạn số nguyên; gần
            // như không thể xảy ra với giá <= 50 triệu): KHÔNG 500. Trả giá gốc,
            // không giảm, kèm thông báo có mã — học sinh vẫn xoá bớt khóa được.
            $coupon = null;
            $pricing = $this->pricing->calculate($lines);
            $notices[] = [
                'code' => self::NOTICE_PRICING_LIMIT,
                'message' => 'Giỏ hàng quá lớn để áp dụng giảm giá, vui lòng bớt khóa học khỏi giỏ.',
            ];
        }

        return new CartView($cart, $items, $unavailable, $coupon, $pricing, $notices);
    }
}
