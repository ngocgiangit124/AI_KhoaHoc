<?php

namespace App\Http\Requests\Admin\Order;

use App\Models\Order;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hoàn tiền (api-contract §2.5 / §2.5.1): `confirm` bắt buộc `true`, `note` tuỳ chọn ≤ 1000 ký tự văn bản thuần.
 * Quyền (`OrderPolicy@refund`) kiểm TRƯỚC validate: giáo viên gửi payload sai vẫn 403.
 */
class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refund', Order::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');

        if (is_string($note)) {
            $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText(allowNewlines: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Vui lòng xác nhận hoàn tiền.',
            'confirm.accepted' => 'Vui lòng xác nhận hoàn tiền.',
            'note.string' => 'Ghi chú không hợp lệ.',
            'note.max' => 'Ghi chú tối đa 1.000 ký tự.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && $note !== '' ? $note : null;
    }
}
