<?php

namespace App\Http\Requests\Admin\Order;

use App\Models\Order;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quản trị viên huỷ đơn thủ công (api-contract §2.5.1): `reason` 5–500 ký tự (hiện cho học sinh), `note` tuỳ chọn ≤ 1000 (nội bộ).
 * Quyền (`OrderPolicy@cancelAsStaff`) kiểm TRƯỚC validate.
 */
class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancelAsStaff', Order::class) === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'note'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
                $this->merge([$key => $value === '' ? null : $value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(allowNewlines: true)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText(allowNewlines: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Vui lòng nhập lý do huỷ (học sinh sẽ thấy lý do này).',
            'reason.string' => 'Lý do huỷ không hợp lệ.',
            'reason.min' => 'Lý do huỷ cần ít nhất 5 ký tự.',
            'reason.max' => 'Lý do huỷ tối đa 500 ký tự.',
            'note.string' => 'Ghi chú không hợp lệ.',
            'note.max' => 'Ghi chú tối đa 1.000 ký tự.',
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && $v !== '' ? $v : null;
    }
}
