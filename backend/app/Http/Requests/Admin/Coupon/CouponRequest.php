<?php

namespace App\Http\Requests\Admin\Coupon;

use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tạo/sửa mã giảm giá (US-013). Quyền kiểm TRƯỚC validate. Mã được chuẩn hoá (cắt khoảng trắng, chữ hoa) trước
 * khi kiểm định dạng/trùng (BR1). Các quy tắc chéo trường (hạn, giới hạn bắt buộc, khoá sau khi đã dùng) nằm ở
 * CouponService vì cần dữ liệu hiện tại/khoá dòng.
 */
class CouponRequest extends FormRequest
{
    /** Trần giá trị giảm cố định (VNĐ) — chặn nhập nhầm thừa số 0. */
    public const MAX_FIXED_AMOUNT = 100_000_000;

    public const MAX_USES_CAP = 1_000_000;

    public function authorize(): bool
    {
        $coupon = $this->route('coupon');

        return $coupon instanceof Coupon
            ? Gate::allows('update', $coupon)
            : Gate::allows('create', Coupon::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('code'))) {
            $merge['code'] = Coupon::normalizeCode($this->input('code'));
        }

        if (is_string($this->input('name'))) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $this->input('name')));
            $merge['name'] = $name === '' ? null : $name;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Coupon|null $coupon */
        $coupon = $this->route('coupon');

        $isPercent = $this->input('discount_type') === CouponDiscountType::Percent->value;

        return [
            'code' => [
                'required', 'string', 'min:4', 'max:50', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon?->getKey()),
            ],
            'name' => ['nullable', 'string', 'max:255', new PlainText],
            'discount_type' => ['required', 'string', Rule::enum(CouponDiscountType::class)],
            'discount_value' => ['required', 'integer', 'min:1', 'max:'.($isPercent ? 100 : self::MAX_FIXED_AMOUNT)],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_USES_CAP],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'course_ids' => ['nullable', 'array', 'max:200'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'subject_ids' => ['nullable', 'array', 'max:200'],
            'subject_ids.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã giảm giá.',
            'code.min' => 'Mã giảm giá tối thiểu 4 ký tự.',
            'code.max' => 'Mã giảm giá tối đa 50 ký tự.',
            'code.regex' => 'Mã giảm giá chỉ gồm chữ cái không dấu, chữ số, dấu gạch ngang và gạch dưới.',
            'code.unique' => 'Mã giảm giá đã tồn tại.',
            'name.max' => 'Tên chương trình tối đa 255 ký tự.',
            'discount_type.required' => 'Vui lòng chọn loại giảm giá.',
            'discount_type.enum' => 'Loại giảm giá không hợp lệ (percent hoặc fixed_amount).',
            'discount_value.required' => 'Vui lòng nhập giá trị giảm.',
            'discount_value.integer' => 'Giá trị giảm phải là số nguyên.',
            'discount_value.min' => 'Giá trị giảm phải lớn hơn 0.',
            'discount_value.max' => $this->input('discount_type') === CouponDiscountType::Percent->value
                ? 'Giảm theo phần trăm tối đa 100%.'
                : 'Giá trị giảm tối đa '.number_format(self::MAX_FIXED_AMOUNT, 0, ',', '.').' đồng.',
            'max_uses.integer' => 'Tổng số lượt dùng phải là số nguyên.',
            'max_uses.min' => 'Tổng số lượt dùng tối thiểu là 1 (để trống nếu không giới hạn).',
            'max_uses.max' => 'Tổng số lượt dùng tối đa '.number_format(self::MAX_USES_CAP, 0, ',', '.').'.',
            'valid_from.date' => 'Ngày bắt đầu hiệu lực không hợp lệ.',
            'valid_until.date' => 'Ngày kết thúc hiệu lực không hợp lệ.',
            'course_ids.array' => 'Danh sách khóa học không hợp lệ.',
            'course_ids.max' => 'Tối đa 200 khóa học trong phạm vi áp dụng.',
            'course_ids.*.exists' => 'Có khóa học trong phạm vi không tồn tại.',
            'course_ids.*.distinct' => 'Danh sách khóa học bị trùng.',
            'course_ids.*.integer' => 'Mã khóa học không hợp lệ.',
            'subject_ids.array' => 'Danh sách chuyên đề không hợp lệ.',
            'subject_ids.max' => 'Tối đa 200 chuyên đề trong phạm vi áp dụng.',
            'subject_ids.*.exists' => 'Có chuyên đề trong phạm vi không tồn tại.',
            'subject_ids.*.distinct' => 'Danh sách chuyên đề bị trùng.',
            'subject_ids.*.integer' => 'Mã chuyên đề không hợp lệ.',
        ];
    }

    /**
     * Dữ liệu đã chuẩn hoá cho CouponService.
     *
     * @return array{code: string, name: ?string, discount_type: CouponDiscountType, discount_value: int, max_uses: ?int, valid_from: ?Carbon, valid_until: ?Carbon, course_ids: list<int>, subject_ids: list<int>}
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'discount_type' => CouponDiscountType::from($data['discount_type']),
            'discount_value' => (int) $data['discount_value'],
            'max_uses' => isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            'valid_from' => isset($data['valid_from']) ? Carbon::parse($data['valid_from'])->setTimezone(config('app.timezone')) : null,
            'valid_until' => isset($data['valid_until']) ? Carbon::parse($data['valid_until'])->setTimezone(config('app.timezone')) : null,
            'course_ids' => array_values(array_map('intval', $data['course_ids'] ?? [])),
            'subject_ids' => array_values(array_map('intval', $data['subject_ids'] ?? [])),
        ];
    }
}
