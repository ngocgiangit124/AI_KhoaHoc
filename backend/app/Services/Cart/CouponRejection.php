<?php

namespace App\Services\Cart;

use App\Exceptions\DomainException;

/**
 * Lý do NỘI BỘ một mã không áp dụng được. Phía client KHÔNG bao giờ thấy lý do
 * chi tiết của nhóm "mã không dùng được" (S18 — chống dò mã): không tồn tại /
 * chưa bắt đầu / đã vô hiệu / hết hạn / hết lượt đều ra CÙNG `COUPON_INVALID`
 * và cùng thông điệp. Chỉ hai lý do gắn với NGƯỜI DÙNG/GIỎ của chính họ được
 * giữ mã riêng (api-contract §1.7): `COUPON_ALREADY_USED` (học sinh tự biết vì
 * đã dùng) và `COUPON_NOT_APPLICABLE` (giỏ không có khóa thuộc phạm vi).
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
     * Mã lỗi công khai. Muốn tách lại `COUPON_EXPIRED`/`COUPON_EXHAUSTED` cho
     * UX thì chỉ cần đổi bảng ánh xạ này (api-contract §1.7 còn giữ 2 mã đó).
     */
    public function errorCode(): string
    {
        return match ($this) {
            self::AlreadyUsed => 'COUPON_ALREADY_USED',
            self::NotApplicable => 'COUPON_NOT_APPLICABLE',
            default => 'COUPON_INVALID',
        };
    }

    public function publicMessage(): string
    {
        return match ($this) {
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
