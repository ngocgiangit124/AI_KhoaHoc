<?php

namespace App\Http\Requests\Admin;

use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use App\Rules\PlainText;
use App\Services\Coupon\CouponService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * api-contract §2.5 — `CouponRequest`: dùng chung tạo (`POST /admin/coupons`)
 * và sửa (`PUT /admin/coupons/{coupon}`). Phân quyền chạy TRƯỚC validate qua
 * middleware `can:...` trên route (routes/admin.php, mẫu T06) — `authorize()`
 * giữ `true` theo quy ước dự án.
 */
class CouponRequest extends FormRequest
{
    /**
     * US-013 đặc tả UX §2.2, S18/DBA #5 (docs/db/design-review.md §2.9) — mã
     * giảm 100% hoặc mã `fixed_amount` ≥ giá khóa rẻ nhất đang bán trong phạm
     * vi áp dụng BẮT BUỘC có `max_uses` + `valid_until` (chống lộ mã bị khai
     * thác hàng loạt / giữ chỗ ảo vô thời hạn). CHECK DB
     * `chk_coupons_full_discount_limited` chỉ phủ được trường hợp percent=100
     * (tĩnh); trường hợp fixed ≥ giá rẻ nhất phụ thuộc `courses.price` thay
     * đổi theo thời gian nên chỉ kiểm được ở đây (App); `CouponService` ghi cờ
     * `high_risk_full_discount` vào audit.
     */
    private const HIGH_DISCOUNT_MESSAGE = 'Mã giảm 100% (hoặc giảm hết giá trị đơn hàng thấp nhất) bắt buộc có giới hạn lượt dùng và ngày hết hạn để tránh bị lộ mã và giữ chỗ ảo.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Coupon|null $coupon */
        $coupon = $this->route('coupon');

        return [
            // BR1 — không phân biệt hoa/thường: `code` đã chuẩn hoá UPPERCASE
            // ở prepareForValidation(); unique dựa vào collation _ai_ci của
            // connection (giống subjects.name — T06/T07).
            'code' => [
                'required',
                'string',
                'min:4',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon?->getKey()),
            ],
            'name' => ['nullable', 'string', 'max:255', new PlainText],
            'discount_type' => ['required', Rule::enum(CouponDiscountType::class)],
            'discount_value' => [
                'required',
                'integer',
                'min:1',
                'max:1000000000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('discount_type') === CouponDiscountType::Percent->value && (int) $value > 100) {
                        $fail('Giá trị giảm không được vượt quá 100%.');
                    }
                },
            ],
            // R5 — không hạ dưới `used_count`; trần để không tràn cột unsigned int (422, không 500).
            'max_uses' => ['nullable', 'integer', 'min:'.max(1, (int) $coupon?->used_count), 'max:1000000'],
            'valid_from' => ['required', 'date'],
            // data-model §3.5: `valid_until >= valid_from` (AC5 chỉ đòi hỏi
            // không được TRƯỚC ngày bắt đầu, không cấm trùng ngày).
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardImmutableFieldsWhenUsed($validator);
            $this->guardLimitsForHighDiscount($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã giảm giá.',
            'code.min' => 'Mã giảm giá phải có ít nhất 4 ký tự.',
            'code.regex' => 'Mã giảm giá chỉ gồm chữ in hoa, số, gạch dưới và gạch ngang.',
            'code.unique' => 'Mã giảm giá đã tồn tại.',
            'discount_type.required' => 'Vui lòng chọn loại giảm giá.',
            'discount_value.required' => 'Vui lòng nhập giá trị giảm.',
            'max_uses.min' => 'Số lượt tối đa không được thấp hơn số lượt đã dùng (và tối thiểu 1).',
            'valid_from.required' => 'Vui lòng chọn ngày bắt đầu hiệu lực.',
            'valid_until.after_or_equal' => 'Ngày kết thúc phải sau ngày bắt đầu.',
        ];
    }

    /**
     * US-013 đặc tả UX §2.2 — mã đã có lượt dùng (`used_count > 0`): không
     * cho đổi `code`/`discount_type`/`discount_value` (ADR-001 §6, giữ toàn
     * vẹn dữ liệu đơn hàng đã áp mã).
     */
    private function guardImmutableFieldsWhenUsed(Validator $validator): void
    {
        /** @var Coupon|null $coupon */
        $coupon = $this->route('coupon');

        if ($coupon === null || $coupon->used_count <= 0) {
            return;
        }

        $locked = [
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => (string) $coupon->discount_value,
        ];

        foreach ($locked as $field => $currentValue) {
            if ($this->has($field) && (string) $this->input($field) !== (string) $currentValue) {
                $validator->errors()->add(
                    $field,
                    'Mã đã được sử dụng nên không thể đổi mã, loại hoặc giá trị giảm.'
                );
            }
        }
    }

    private function guardLimitsForHighDiscount(Validator $validator): void
    {
        $type = $this->input('discount_type');
        $rawValue = $this->input('discount_value');

        if (! is_string($type) || CouponDiscountType::tryFrom($type) === null || ! is_numeric($rawValue)) {
            // Để rule discount_type/discount_value ở trên báo lỗi riêng.
            return;
        }

        // Phép tính "rủi ro cao" dùng chung với ghi audit nằm ở CouponService (R2/R7).
        $isHighRisk = app(CouponService::class)->isHighRisk(
            $type,
            (int) $rawValue,
            array_values(array_map('intval', (array) $this->input('course_ids', []))),
            array_values(array_map('intval', (array) $this->input('subject_ids', []))),
        );

        if (! $isHighRisk) {
            return;
        }

        if (blank($this->input('max_uses'))) {
            $validator->errors()->add('max_uses', self::HIGH_DISCOUNT_MESSAGE);
        }

        if (blank($this->input('valid_until'))) {
            $validator->errors()->add('valid_until', self::HIGH_DISCOUNT_MESSAGE);
        }
    }
}
