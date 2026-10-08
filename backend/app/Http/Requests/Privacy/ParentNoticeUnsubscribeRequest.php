<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /parent-notices/unsubscribe: token trong JSON `token`, hoặc one-click (RFC 8058) `?t=` + form `List-Unsubscribe=One-Click`.
 */
class ParentNoticeUnsubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('token') && $this->query('t') !== null) {
            $this->merge(['token' => $this->query('t')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:512']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Liên kết không hợp lệ.',
            'token.string' => 'Liên kết không hợp lệ.',
            'token.max' => 'Liên kết không hợp lệ.',
        ];
    }
}
