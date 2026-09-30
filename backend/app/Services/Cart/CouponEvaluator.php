<?php

namespace App\Services\Cart;

use App\Enums\CouponStatus;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Đánh giá một mã giảm giá với giỏ hàng của một học sinh (US-004 BR7-BR9,
 * US-013). Chỉ ĐỌC: không khoá, không tăng `used_count` (lượt dùng chỉ bị trừ
 * khi đơn thanh toán thành công — T18/T19; ở giỏ chỉ kiểm còn chỗ theo
 * `used_count`, sức chứa cả đơn pending kiểm lại khi checkout — ADR-001 §6).
 *
 * S18 (chống dò mã): thứ tự kiểm cố định "mã dùng được? → học sinh đã dùng? →
 * phạm vi"; nhóm đầu ra cùng `COUPON_INVALID` (xem `CouponRejection`).
 */
class CouponEvaluator
{
    /** `coupons.code` varchar(50), chỉ `[A-Z0-9_-]` (CouponRequest quản trị). */
    private const CODE_PATTERN = '/^[A-Z0-9_-]{1,50}$/';

    public function __construct(private readonly CouponUsageChecker $usage) {}

    /**
     * Chuẩn hoá đầu vào (trim + UPPERCASE, BR1 US-013) rồi tra theo `code` —
     * `WHERE code = ?` để dùng unique index (T15-review §3). Chuỗi không khớp
     * định dạng mã hợp lệ thì KHÔNG chạm DB (cùng kết quả "không tồn tại").
     */
    public function find(string $rawCode): ?Coupon
    {
        // Chỉ cắt khoảng trắng thường (không cắt NUL của `trim()` mặc định).
        $code = mb_strtoupper(trim($rawCode, " \t\n\r"));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }

        return Coupon::query()->where('code', $code)->first();
    }

    /**
     * Tra mã theo chuỗi người dùng nhập và ném lỗi nếu không dùng được.
     *
     * @param  list<int>  $courseIds  khóa CÓ THỂ MUA trong giỏ (đã loại khóa unavailable)
     *
     * @throws DomainException `COUPON_INVALID|COUPON_ALREADY_USED|COUPON_NOT_APPLICABLE` (422)
     */
    public function resolveOrFail(string $rawCode, User $user, array $courseIds): Coupon
    {
        $coupon = $this->find($rawCode);

        $rejection = $coupon === null
            ? CouponRejection::NotFound
            : $this->rejection($coupon, $user, $courseIds);

        if ($rejection !== null) {
            throw $rejection->toException();
        }

        /** @var Coupon $coupon */
        return $coupon;
    }

    /**
     * `null` = áp dụng được. Dùng cả cho kiểm lại mã đang nằm trong giỏ (mã bị
     * vô hiệu/hết hạn/hết lượt, hoặc giỏ đổi khiến hết phạm vi — US-004 AC10).
     *
     * @param  list<int>  $courseIds
     */
    public function rejection(Coupon $coupon, User $user, array $courseIds): ?CouponRejection
    {
        if ($coupon->status !== CouponStatus::Active) {
            return CouponRejection::Inactive;
        }

        $now = now();

        if ($coupon->valid_from->greaterThan($now)) {
            return CouponRejection::NotStarted;
        }

        if ($coupon->valid_until !== null && $coupon->valid_until->lessThan($now)) {
            return CouponRejection::Expired;
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            return CouponRejection::Exhausted;
        }

        if ($this->usage->hasUsed($coupon, $user)) {
            return CouponRejection::AlreadyUsed;
        }

        if ($this->eligibleCourseIds($coupon, $courseIds) === []) {
            return CouponRejection::NotApplicable;
        }

        return null;
    }

    /**
     * Khóa trong `$courseIds` thuộc phạm vi mã: mã không giới hạn → tất cả; mã
     * giới hạn → khóa trong `coupon_course` HOẶC thuộc ≥ 1 chuyên đề trong
     * `coupon_subject` (hợp — data-model §3.5). Chuyên đề bị ẩn vẫn tính.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function eligibleCourseIds(Coupon $coupon, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        if (! $coupon->is_restricted) {
            return $courseIds;
        }

        $direct = DB::table('coupon_course')
            ->where('coupon_id', $coupon->id)
            ->whereIn('course_id', $courseIds)
            ->pluck('course_id');

        $viaSubject = DB::table('course_subject')
            ->whereIn('course_id', $courseIds)
            ->whereIn('subject_id', DB::table('coupon_subject')->where('coupon_id', $coupon->id)->select('subject_id'))
            ->pluck('course_id');

        $eligible = $direct->merge($viaSubject)->map(fn ($id) => (int) $id)->flip();

        return array_values(array_filter($courseIds, fn (int $id) => $eligible->has($id)));
    }
}
