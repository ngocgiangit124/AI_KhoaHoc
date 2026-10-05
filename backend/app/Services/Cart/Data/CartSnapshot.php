<?php

namespace App\Services\Cart\Data;

use App\Models\CartItem;
use App\Models\Coupon;

/**
 * Ảnh chụp giỏ đã đánh giá lại: dòng hợp lệ (được tính tiền), dòng không còn khả dụng, mã đang áp (null nếu
 * không có hoặc vừa bị gỡ) và giá. `notices` là thông báo cho người dùng (`code`, `message`).
 */
final readonly class CartSnapshot
{
    /**
     * @param  list<CartItem>  $items  Dòng hợp lệ (khóa published, chưa xoá, price > 0, chưa sở hữu), mới thêm trước.
     * @param  list<CartItem>  $unavailableItems  Dòng không còn khả dụng (không tính vào giá).
     * @param  list<array{code: string, message: string}>  $notices
     */
    public function __construct(
        public array $items,
        public array $unavailableItems,
        public ?Coupon $coupon,
        public ?CouponEvaluation $evaluation,
        public Pricing $pricing,
        public array $notices,
    ) {}

    /** @return list<int> */
    public function courseIds(): array
    {
        return array_map(fn (CartItem $i) => $i->course_id, $this->items);
    }
}
