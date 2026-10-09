<?php

namespace App\Http\Requests\Admin\Order;

use App\Models\Order;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/** Ghi chú nội bộ (api-contract §2.5.1): `body` 1–1000 ký tự sau trim. Quyền (`OrderPolicy@addNote`) kiểm TRƯỚC validate. */
class StoreOrderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addNote', Order::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
            $this->merge(['body' => $body === '' ? null : $body]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000', new PlainText(allowNewlines: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Vui lòng nhập nội dung ghi chú.',
            'body.string' => 'Nội dung ghi chú không hợp lệ.',
            'body.max' => 'Ghi chú tối đa 1.000 ký tự.',
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
