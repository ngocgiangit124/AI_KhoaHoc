<?php

namespace App\Services\Cart;

use App\Exceptions\DomainException;

/**
 * Lý do một mã không áp dụng được, ánh xạ sang mã lỗi công khai theo
 * api-contract §1.7 và tasks.md T16 (S18 — chống dò mã):
 * - `COUPON_INVALID` (gộp): không tồn tại / chưa bắt đầu / đã vô hiệu — học
 *   sinh không phân biệt được 3 trường hợp này.
 * - `COUPON_EXPIRED`, `COUPON_EXHAUSTED`, `COUPON_ALREADY_USED`,
 *   `COUPON_NOT_APPLICABLE`: mã riêng, cần cho UX (US-004 AC8 "báo lỗi rõ ràng
 *   tương ứng"; audit S18 chủ ý giữ các mã này).
 *
 * Giả định: api-contract §1.7 chỉ định `COUPON_EXHAUSTED` là 409 "khi tạo
 * đơn/tạo link mới" (checkout, T18). Ở GIỎ dùng cùng tên mã nhưng 422 (giống
 * mọi lỗi mã khác của `PUT /cart/coupon`); 409 dành cho checkout.
 */
enum CouponRejection: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case AlreadyUsed = 'already_used';
    case NotApplicable = 'not_applicable';

    /**
     * Mã lỗi công khai (bảng ánh xạ duy nhất).
     */
    public function errorCode(): string
    {
        return match ($this) {
            self::Expired => 'COUPON_EXPIRED',
            self::Exhausted => 'COUPON_EXHAUSTED',
            self::AlreadyUsed => 'COUPON_ALREADY_USED',
            self::NotApplicable => 'COUPON_NOT_APPLICABLE',
            default => 'COUPON_INVALID',
        };
    }

    public function publicMessage(): string
    {
        return match ($this) {
            self::Expired => 'Mã giảm giá đã hết hạn.',
            self::Exhausted => 'Mã giảm giá đã hết lượt sử dụng.',
            self::AlreadyUsed => 'Bạn đã sử dụng mã giảm giá này rồi.',
            self::NotApplicable => 'Mã giảm giá không áp dụng cho khóa học nào trong giỏ hàng của bạn.',
            default => 'Mã giảm giá không hợp lệ hoặc không còn hiệu lực.',
        };
    }

    public function toException(): DomainException
    {
        return new DomainException($this->errorCode(), $this->publicMessage(), 422);
    }
}
