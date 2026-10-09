<?php

namespace App\Services\Coupons;

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Enums\CourseStatus;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quản trị mã giảm giá (US-013). `code/status/is_restricted/created_by` chỉ đổi ở đây (S17); `used_count`
 * KHÔNG đổi ở đây (T19 khi đơn paid + `counters:recount`).
 *
 * Payload (từ CouponRequest::payload): code, name, discount_type (enum), discount_value, max_uses,
 * valid_from (null = giữ nguyên/bây giờ), valid_until, course_ids, subject_ids.
 *
 * Khoá dòng: mọi ghi vào `coupons` ở đây đều `lockForUpdate` theo PK, nằm trong chuỗi khoá chuẩn
 * `carts → orders → coupons` (data-model §4) vì service không chạm bảng nào đứng trước `coupons`.
 *
 * @phpstan-type Payload array{code: string, name: ?string, discount_type: CouponDiscountType, discount_value: int, max_uses: ?int, valid_from: ?Carbon, valid_until: ?Carbon, course_ids: list<int>, subject_ids: list<int>}
 */
class CouponService
{
    /** Trường bị khoá khi mã đã có lượt dùng/đơn tham chiếu (api-contract: không đổi code/type/value). */
    private const LOCKED_FIELDS = ['code', 'discount_type', 'discount_value'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  Payload  $data
     */
    public function create(User $actor, array $data): Coupon
    {
        $validFrom = $data['valid_from'] ?? now();
        $this->assertLimits($data, $validFrom, 0);

        try {
            return DB::transaction(function () use ($actor, $data, $validFrom): Coupon {
                $coupon = new Coupon([
                    'name' => $data['name'],
                    'discount_type' => $data['discount_type'],
                    'discount_value' => $data['discount_value'],
                    'max_uses' => $data['max_uses'],
                    'valid_from' => $validFrom,
                    'valid_until' => $data['valid_until'],
                ]);
                $coupon->forceFill([
                    'code' => $data['code'],
                    'status' => CouponStatus::Active,
                    'used_count' => 0,
                    'is_restricted' => $this->isRestricted($data),
                    'created_by' => $actor->getKey(),
                ])->save();

                $coupon->courses()->sync($data['course_ids']);
                $coupon->subjects()->sync($data['subject_ids']);

                $this->audit->log('coupon.create', $coupon, $this->snapshot($coupon, $data) + ['high_risk' => $this->isHighRisk($data)]);

                return $coupon->load(['courses:id,title', 'subjects:id,name']);
            });
        } catch (QueryException $e) {
            throw $this->translateDuplicate($e);
        }
    }

    /**
     * @param  Payload  $data
     */
    public function update(Coupon $coupon, array $data): Coupon
    {
        try {
            return DB::transaction(function () use ($coupon, $data): Coupon {
                $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();

                $used = $this->isUsed($locked);
                $changedLocked = $used ? $this->changedLockedFields($locked, $data) : [];

                if ($changedLocked !== []) {
                    throw new DomainException(
                        'COUPON_LOCKED',
                        'Mã đã có lượt sử dụng nên không đổi được mã, loại giảm hoặc giá trị giảm. Hãy vô hiệu hoá mã này và tạo mã mới.',
                        422,
                        ['fields' => $changedLocked],
                    );
                }

                $validFrom = $data['valid_from'] ?? $locked->valid_from;
                $this->assertLimits($data, $validFrom, $locked->used_count);

                $before = $this->snapshot($locked, null) + [
                    'course_ids' => $locked->courses()->pluck('courses.id')->sort()->values()->all(),
                    'subject_ids' => $locked->subjects()->pluck('subjects.id')->sort()->values()->all(),
                ];

                $locked->fill([
                    'name' => $data['name'],
                    'discount_type' => $data['discount_type'],
                    'discount_value' => $data['discount_value'],
                    'max_uses' => $data['max_uses'],
                    'valid_from' => $validFrom,
                    'valid_until' => $data['valid_until'],
                ]);
                $locked->forceFill(['code' => $data['code'], 'is_restricted' => $this->isRestricted($data)])->save();

                $locked->courses()->sync($data['course_ids']);
                $locked->subjects()->sync($data['subject_ids']);

                $after = $this->snapshot($locked->refresh(), $data);
                $diff = [];
                foreach ($after as $key => $value) {
                    if (($before[$key] ?? null) !== $value) {
                        $diff[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
                    }
                }

                if ($diff !== []) {
                    $this->audit->log('coupon.update', $locked, $diff + ['high_risk' => $this->isHighRisk($data)]);
                }

                return $locked->load(['courses:id,title', 'subjects:id,name']);
            });
        } catch (QueryException $e) {
            throw $this->translateDuplicate($e);
        }
    }

    public function deactivate(Coupon $coupon): Coupon
    {
        return $this->setStatus($coupon, CouponStatus::Inactive, 'coupon.deactivate');
    }

    public function activate(Coupon $coupon): Coupon
    {
        return $this->setStatus($coupon, CouponStatus::Active, 'coupon.activate');
    }

    /**
     * Xoá cứng chỉ khi chưa từng dùng (US-013 biên): used_count = 0 và không còn đơn/lượt dùng tham chiếu.
     * Mã đã dùng chỉ vô hiệu hoá. FK `orders.coupon_id`/`coupon_usages.coupon_id` (T18) là chốt chặn cuối.
     */
    public function delete(Coupon $coupon): void
    {
        try {
            DB::transaction(function () use ($coupon): void {
                $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->first();

                // Đã bị xoá đồng thời: coi như xong (idempotent, 204).
                if ($locked === null) {
                    return;
                }

                if ($this->isUsed($locked)) {
                    throw $this->inUse();
                }

                $locked->delete();
                $this->audit->log('coupon.delete', $locked, ['coupon_code' => $locked->code]);
            });
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452], true)) {
                throw $this->inUse();
            }

            throw $e;
        }
    }

    private function setStatus(Coupon $coupon, CouponStatus $status, string $action): Coupon
    {
        DB::transaction(function () use ($coupon, $status, $action): void {
            $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $status) {
                $locked->forceFill(['status' => $status])->save();
                $this->audit->log($action, $locked, ['coupon_code' => $locked->code]);
            }

            $coupon->setRawAttributes($locked->getAttributes(), true);
        });

        return $coupon;
    }

    /**
     * Đã dùng = có lượt dùng ghi nhận (`used_count`/`coupon_usages`), hoặc còn đơn tham chiếu mã (mọi trạng thái:
     * đơn pending đang giữ chỗ cũng khoá đổi giá trị mã). FK `orders.coupon_id`/`coupon_usages.coupon_id` là chốt chặn cuối.
     */
    protected function isUsed(Coupon $coupon): bool
    {
        if ($coupon->used_count > 0) {
            return true;
        }

        return DB::table('coupon_usages')->where('coupon_id', $coupon->getKey())->exists()
            || DB::table('orders')->where('coupon_id', $coupon->getKey())->exists();
    }

    /**
     * @param  Payload  $data
     * @return list<string>
     */
    private function changedLockedFields(Coupon $coupon, array $data): array
    {
        $changed = [];

        foreach (self::LOCKED_FIELDS as $field) {
            $old = $coupon->{$field};
            $new = $data[$field];

            if ($old instanceof CouponDiscountType) {
                $old = $old->value;
            }
            if ($new instanceof CouponDiscountType) {
                $new = $new->value;
            }

            if ($old !== $new) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Quy tắc chéo trường (AC5, S18). Ném ValidationException để trả 422 theo từng trường như Form Request.
     *
     * @param  Payload  $data
     */
    private function assertLimits(array $data, Carbon $validFrom, int $usedCount): void
    {
        $errors = [];

        if ($data['valid_until'] !== null && $data['valid_until']->lt($validFrom)) {
            $errors['valid_until'] = 'Ngày kết thúc hiệu lực phải sau hoặc bằng ngày bắt đầu.';
        }

        if ($data['max_uses'] !== null && $data['max_uses'] < $usedCount) {
            $errors['max_uses'] = "Tổng số lượt dùng không được nhỏ hơn số lượt đã dùng ({$usedCount}).";
        }

        // S18: mã giảm 100% hoặc giảm cố định ≥ giá khóa rẻ nhất đang bán phải có giới hạn lượt và hạn dùng.
        if ($this->isHighRisk($data)) {
            if ($data['max_uses'] === null) {
                $errors['max_uses'] = 'Mã giảm 100% hoặc giảm số tiền không nhỏ hơn giá khóa rẻ nhất phải giới hạn tổng số lượt dùng.';
            }
            if ($data['valid_until'] === null) {
                $errors['valid_until'] = 'Mã giảm 100% hoặc giảm số tiền không nhỏ hơn giá khóa rẻ nhất phải có ngày kết thúc hiệu lực.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Payload  $data
     */
    private function isHighRisk(array $data): bool
    {
        if ($data['discount_type'] === CouponDiscountType::Percent) {
            return $data['discount_value'] >= 100;
        }

        $cheapest = $this->cheapestSellingPrice();

        return $cheapest !== null && $data['discount_value'] >= $cheapest;
    }

    /** Giá của khóa rẻ nhất đang bán: đã xuất bản, chưa xoá mềm, có phí (price > 0). Null nếu chưa có khóa nào. */
    public function cheapestSellingPrice(): ?int
    {
        $price = DB::table('courses')
            ->where('status', CourseStatus::Published->value)
            ->whereNull('deleted_at')
            ->where('price', '>', 0)
            ->min('price');

        return $price === null ? null : (int) $price;
    }

    /**
     * @param  Payload  $data
     */
    private function isRestricted(array $data): bool
    {
        return $data['course_ids'] !== [] || $data['subject_ids'] !== [];
    }

    /**
     * Ảnh chụp trường để audit (không PII/secret). `$data` null: đọc từ model (trạng thái trước khi sửa).
     *
     * @param  Payload|null  $data
     * @return array<string, mixed>
     */
    private function snapshot(Coupon $coupon, ?array $data): array
    {
        $snap = [
            'coupon_code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => $coupon->discount_value,
            'max_uses' => $coupon->max_uses,
            'valid_from' => $coupon->valid_from->toIso8601String(),
            'valid_until' => $coupon->valid_until?->toIso8601String(),
            'is_restricted' => $coupon->is_restricted,
        ];

        if ($data !== null) {
            $snap['course_ids'] = collect($data['course_ids'])->sort()->values()->all();
            $snap['subject_ids'] = collect($data['subject_ids'])->sort()->values()->all();
        }

        return $snap;
    }

    private function inUse(): DomainException
    {
        return new DomainException(
            'COUPON_IN_USE',
            'Mã giảm giá đã được sử dụng nên không thể xoá. Hãy vô hiệu hoá mã thay vì xoá.',
            409,
        );
    }

    /** Tạo/sửa đồng thời cùng mã: unique index thắng → báo lỗi y như validate (không 500). */
    private function translateDuplicate(QueryException $e): \Throwable
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ValidationException::withMessages(['code' => 'Mã giảm giá đã tồn tại.']);
        }

        return $e;
    }
}
