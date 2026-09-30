<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;

/**
 * Ảnh chụp giỏ hàng để trả cho client (đã tính giá, đã kiểm lại mã).
 * `unavailableCourseIds`: khóa trong giỏ hiện KHÔNG mua được (gỡ publish, xoá
 * mềm, đã thành miễn phí, đã sở hữu / đang chờ duyệt) — không tính vào giá.
 */
final readonly class CartView
{
    /**
     * @param  list<CartItem>  $items  theo thứ tự thêm (cũ → mới), `course` đã eager-load
     * @param  list<int>  $unavailableCourseIds
     * @param  list<array{code: string, message: string, course_id?: int}>  $notices
     */
    public function __construct(
        public ?Cart $cart,
        public array $items,
        public array $unavailableCourseIds,
        public ?Coupon $coupon,
        public PricingResult $pricing,
        public array $notices,
    ) {}

    public function isUnavailable(int $courseId): bool
    {
        return in_array($courseId, $this->unavailableCourseIds, true);
    }

    /**
     * @return list<int>
     */
    public function purchasableCourseIds(): array
    {
        $ids = [];

        foreach ($this->items as $item) {
            if (! $this->isUnavailable($item->course_id)) {
                $ids[] = $item->course_id;
            }
        }

        return $ids;
    }
}
