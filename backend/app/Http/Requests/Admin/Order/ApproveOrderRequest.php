<?php

namespace App\Http\Requests\Admin\Order;

use App\Models\Order;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Duyệt / duyệt muộn đơn thủ công (api-contract §2.5.1). Quyền (`OrderPolicy@approve`) kiểm TRƯỚC validate: giáo viên gửi payload
 * sai vẫn 403.
 */
class ApproveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', Order::class) === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['payment_reference', 'note'] as $key) {
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
            'confirm' => ['required', 'accepted'],
            'late' => ['sometimes', 'boolean'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText(allowNewlines: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Vui lòng xác nhận đã nhận đủ tiền.',
            'confirm.accepted' => 'Vui lòng xác nhận đã nhận đủ tiền.',
            'late.boolean' => 'Giá trị duyệt muộn không hợp lệ.',
            'payment_reference.string' => 'Mã giao dịch không hợp lệ.',
            'payment_reference.max' => 'Mã giao dịch tối đa 100 ký tự.',
            'note.string' => 'Ghi chú không hợp lệ.',
            'note.max' => 'Ghi chú tối đa 1.000 ký tự.',
        ];
    }

    public function isLate(): bool
    {
        return $this->boolean('late');
    }

    public function paymentReference(): ?string
    {
        $v = $this->validated('payment_reference');

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && $v !== '' ? $v : null;
    }
}
