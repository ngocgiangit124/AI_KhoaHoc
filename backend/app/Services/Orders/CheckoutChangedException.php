<?php

namespace App\Services\Orders;

use App\Exceptions\DomainException;
use App\Services\Cart\Data\CartSnapshot;

/**
 * 409 CHECKOUT_CHANGED: giỏ/giá/mã đã đổi so với lúc HS xem. Mang ảnh chụp giỏ MỚI để controller dựng `preview`
 * trong `errors` (api-contract §1.7). `reasons` = mã lý do ngắn (PRICE_CHANGED, COUPON_REMOVED, COUPON_EXHAUSTED,
 * ITEMS_CHANGED).
 */
class CheckoutChangedException extends DomainException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly CartSnapshot $snapshot, public readonly array $reasons)
    {
        parent::__construct('CHECKOUT_CHANGED', 'Giỏ hàng, giá hoặc mã giảm giá đã thay đổi. Vui lòng kiểm tra lại đơn hàng trước khi thanh toán.', 409);
    }
}
