<?php

namespace App\Services\Coupon;

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * US-013 — CRUD mã giảm giá quản trị. `used_count`/`coupon_usages` (nguồn sự
 * thật khi order thanh toán thành công — BR7) thuộc T18 (Checkout); ở đây chỉ
 * ĐỌC `used_count` denormalize (mặc định 0) để quyết định có cho sửa/xoá hay
 * không (US-013 "Trường hợp biên & lỗi").
 */
class CouponService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{code:string,name?:string|null,discount_type:string,discount_value:int,
     *     max_uses?:int|null,valid_from:string,valid_until?:string|null,
     *     course_ids?:list<int>,subject_ids?:list<int>}  $data
     */
    public function create(array $data, User $actor): Coupon
    {
        return DB::transaction(function () use ($data, $actor): Coupon {
            $courseIds = $data['course_ids'] ?? [];
            $subjectIds = $data['subject_ids'] ?? [];

            $coupon = new Coupon([
                'code' => $data['code'],
                'name' => $data['name'] ?? null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'max_uses' => $data['max_uses'] ?? null,
                'valid_from' => $data['valid_from'],
                'valid_until' => $data['valid_until'] ?? null,
            ]);
            // S17 — status/is_restricted/created_by KHÔNG nằm trong $fillable,
            // gán trực tiếp (không mass-assign).
            $coupon->status = CouponStatus::Active;
            $coupon->is_restricted = $courseIds !== [] || $subjectIds !== [];
            $coupon->created_by = $actor->getKey();
            $coupon->save();

            $coupon->courses()->sync($courseIds);
            $coupon->subjects()->sync($subjectIds);

            $this->auditLogger->log('coupon.create', $coupon, $this->auditPayload($coupon, $courseIds, $subjectIds));

            return $coupon->load('courses', 'subjects');
        });
    }

    /**
     * @param  array{code:string,name?:string|null,discount_type:string,discount_value:int,
     *     max_uses?:int|null,valid_from:string,valid_until?:string|null,
     *     course_ids?:list<int>,subject_ids?:list<int>}  $data
     */
    public function update(Coupon $coupon, array $data): Coupon
    {
        return DB::transaction(function () use ($coupon, $data): Coupon {
            $courseIds = $data['course_ids'] ?? [];
            $subjectIds = $data['subject_ids'] ?? [];

            // Phòng vệ ở tầng Service (defense in depth, S17-style) — dù
            // `CouponRequest` đã chặn đổi code/discount_type/discount_value
            // khi `used_count > 0`, KHÔNG tin tuyệt đối vào FormRequest cho
            // logic bất biến quan trọng (toàn vẹn dữ liệu đơn hàng đã áp mã).
            if ($coupon->used_count > 0) {
                $data['code'] = $coupon->code;
                $data['discount_type'] = $coupon->discount_type->value;
                $data['discount_value'] = $coupon->discount_value;
            }

            $before = $this->auditPayload(
                $coupon,
                $coupon->courses()->pluck('courses.id')->all(),
                $coupon->subjects()->pluck('subjects.id')->all(),
            );

            $coupon->code = $data['code'];
            $coupon->name = $data['name'] ?? null;
            // Larastan — `Coupon::$discount_type`/`$valid_from`/`$valid_until`
            // khai @property kiểu đã cast (enum/Carbon) để phpstan hiểu đúng
            // khi ĐỌC; gán trực tiếp (khác `fill()`/constructor ở create())
            // cần tự ép kiểu tương ứng trước khi gán.
            $coupon->discount_type = CouponDiscountType::from($data['discount_type']);
            $coupon->discount_value = $data['discount_value'];
            $coupon->max_uses = $data['max_uses'] ?? null;
            $coupon->valid_from = Carbon::parse($data['valid_from']);
            $coupon->valid_until = isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;
            $coupon->is_restricted = $courseIds !== [] || $subjectIds !== [];
            $coupon->save();

            $coupon->courses()->sync($courseIds);
            $coupon->subjects()->sync($subjectIds);
            $coupon->load('courses', 'subjects');

            $this->auditLogger->log('coupon.update', $coupon, [
                'before' => $before,
                'after' => $this->auditPayload($coupon, $courseIds, $subjectIds),
            ]);

            return $coupon;
        });
    }

    public function deactivate(Coupon $coupon): Coupon
    {
        $coupon->status = CouponStatus::Inactive;
        $coupon->save();

        $this->auditLogger->log('coupon.deactivate', $coupon, [
            'coupon_code' => $coupon->code,
        ]);

        return $coupon;
    }

    /**
     * US-013 "Trường hợp biên & lỗi" — mã đã dùng ≥1 đơn hàng (`used_count >
     * 0`) chỉ cho vô hiệu hoá, không cho xoá cứng (giữ toàn vẹn lịch sử đơn
     * hàng đã áp mã đó).
     */
    public function delete(Coupon $coupon): void
    {
        if ($coupon->used_count > 0) {
            throw new DomainException(
                code: 'COUPON_IN_USE',
                message: 'Mã giảm giá đã được sử dụng nên không thể xoá. Hãy vô hiệu hoá thay thế.',
                status: 409,
            );
        }

        $coupon->delete();

        $this->auditLogger->log('coupon.delete', $coupon, [
            'coupon_code' => $coupon->code,
        ]);
    }

    /**
     * @param  list<int>  $courseIds
     * @param  list<int>  $subjectIds
     * @return array<string, mixed>
     */
    private function auditPayload(Coupon $coupon, array $courseIds, array $subjectIds): array
    {
        return [
            // `code` (chính xác, không hậu tố) nằm trong FORBIDDEN_EXACT_KEYS
            // của `AuditLogger` (dành cho mã OTP người dùng nhập) — dùng
            // `coupon_code` (nằm trong ALLOWED_KEYS) để KHÔNG bị lọc mất.
            'coupon_code' => $coupon->code,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => $coupon->discount_value,
            'max_uses' => $coupon->max_uses,
            'valid_from' => $coupon->valid_from->toAtomString(),
            'valid_until' => $coupon->valid_until?->toAtomString(),
            'is_restricted' => $coupon->is_restricted,
            'course_ids' => $courseIds,
            'subject_ids' => $subjectIds,
        ];
    }
}
