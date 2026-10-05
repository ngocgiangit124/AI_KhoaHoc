<?php

namespace App\Services\Cart;

use App\Enums\CouponState;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Cart\Data\CouponEvaluation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm hiệu lực một mã đối với một giỏ (US-004 BR7–BR9, US-013). Dùng chung cho giỏ (T16) và checkout (T18).
 * Không ghi DB, không giữ chỗ lượt dùng (chỉ đọc `used_count`; sức chứa có tính đơn pending là việc của T18).
 *
 * Thứ tự kiểm và mã lỗi (S18 — không lộ mã nào tồn tại ngoài phạm vi cần thiết):
 *   inactive / upcoming → 422 COUPON_INVALID (gộp, như mã không tồn tại)
 *   expired → 422 COUPON_EXPIRED · exhausted → 422 COUPON_EXPIRED (message "hết lượt")
 *   đã dùng (coupon_usages) → 422 COUPON_ALREADY_USED
 *   không có khóa trong giỏ thuộc phạm vi / phạm vi rỗng sau cascade / fixed đưa tổng về 0đ mà thiếu max_uses/valid_until → 422 COUPON_NOT_APPLICABLE
 * Thời gian: `now()` theo `config('app.timezone')` (cột DATETIME lưu giờ theo múi giờ app) — không NOW() của MySQL.
 */
class CouponEvaluator
{
    private ?bool $hasUsagesTable = null;

    /**
     * @param  array<int, int>  $prices  course_id => đơn giá của các khóa hợp lệ trong giỏ
     *
     * @throws DomainException COUPON_INVALID | COUPON_EXPIRED | COUPON_ALREADY_USED | COUPON_NOT_APPLICABLE (422)
     */
    public function evaluate(Coupon $coupon, User $user, array $prices, ?Carbon $now = null): CouponEvaluation
    {
        $now ??= now();

        match ($coupon->state($now)) {
            CouponState::Inactive, CouponState::Upcoming => throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422),
            CouponState::Expired => throw new DomainException('COUPON_EXPIRED', 'Mã giảm giá đã hết hạn.', 422),
            CouponState::Exhausted => throw new DomainException('COUPON_EXPIRED', 'Mã giảm giá đã hết lượt sử dụng.', 422),
            CouponState::Active => null,
        };

        if ($this->alreadyUsedBy($coupon, $user)) {
            throw new DomainException('COUPON_ALREADY_USED', 'Bạn đã sử dụng mã giảm giá này.', 422);
        }

        $eligible = $this->eligibleCourseIds($coupon, array_keys($prices));
        $eligibleSum = array_sum(array_map(fn (int $id) => $prices[$id], $eligible));

        if ($eligible === [] || $this->zeroesCartUnbounded($coupon, $eligibleSum, array_sum($prices))) {
            throw new DomainException('COUPON_NOT_APPLICABLE', 'Mã không áp dụng được cho giỏ hàng hiện tại.', 422);
        }

        return new CouponEvaluation($coupon, $eligible);
    }

    /**
     * Khóa (trong `$courseIds`) thuộc phạm vi mã. Mã không giới hạn: tất cả. Mã giới hạn: khóa nằm trong
     * `coupon_course` HOẶC thuộc ≥ 1 chuyên đề trong `coupon_subject`; pivot rỗng (sau cascade xoá) → rỗng,
     * KHÔNG coi là áp toàn bộ.
     *
     * @param  list<int>  $courseIds
     * @return list<int> Giữ thứ tự `$courseIds`
     */
    public function eligibleCourseIds(Coupon $coupon, array $courseIds): array
    {
        if ($courseIds === [] || ! $coupon->is_restricted) {
            return $courseIds;
        }

        $direct = DB::table('coupon_course')->where('coupon_id', $coupon->getKey())->whereIn('course_id', $courseIds)->pluck('course_id');
        $viaSubject = DB::table('course_subject')
            ->whereIn('course_id', $courseIds)
            ->whereIn('subject_id', DB::table('coupon_subject')->where('coupon_id', $coupon->getKey())->select('subject_id'))
            ->pluck('course_id');

        $allowed = array_flip($direct->merge($viaSubject)->map(fn ($id) => (int) $id)->all());

        return array_values(array_filter($courseIds, fn (int $id) => isset($allowed[$id])));
    }

    /**
     * US-013 BR6: giảm = min(giá trị mã, phần áp dụng) nên mã fixed lớn hơn phần áp dụng VẪN dùng được. Riêng khi mã
     * fixed đưa tổng về 0đ thì chỉ cho khi mã có cả `max_uses` và `valid_until` (ngưỡng high-risk của T15; review T16 M1).
     */
    private function zeroesCartUnbounded(Coupon $coupon, int $eligibleSum, int $subtotal): bool
    {
        if ($coupon->discount_type->value !== 'fixed_amount') {
            return false;
        }

        $bounded = $coupon->max_uses !== null && $coupon->valid_until !== null;

        return ! $bounded && min($coupon->discount_value, $eligibleSum) >= $subtotal;
    }

    /** Mỗi HS dùng mỗi mã 1 lần (BR3). Bảng `coupon_usages` do T18 tạo: chưa có thì chưa ai dùng. */
    protected function alreadyUsedBy(Coupon $coupon, User $user): bool
    {
        $this->hasUsagesTable ??= Schema::hasTable('coupon_usages');

        return $this->hasUsagesTable
            && DB::table('coupon_usages')->where('coupon_id', $coupon->getKey())->where('user_id', $user->getKey())->exists();
    }
}
