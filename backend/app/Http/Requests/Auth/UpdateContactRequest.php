<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /auth/contact — email và/hoặc SĐT mới (≥ 1 field). Cùng quy tắc định dạng/unique với đăng ký.
 */
class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('email'))) {
            $normalized['email'] = mb_strtolower(trim($this->input('email')));
        }

        $phone = PhoneNumber::normalize($this->input('phone'));
        if ($phone !== null) {
            $normalized['phone'] = $phone;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'email' => [
                'required_without:phone', 'nullable', 'string', 'email:rfc,strict',
                'regex:'.RegisterRequest::EMAIL_SAFE_PATTERN, 'max:254',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'required_without:email', 'nullable', 'string', 'regex:/^0[35789]\d{8}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Vui lòng nhập email hoặc số điện thoại mới.',
            'email.email' => 'Email không đúng định dạng.',
            'email.unique' => 'Email đã được sử dụng.',
            'phone.required_without' => 'Vui lòng nhập email hoặc số điện thoại mới.',
            'phone.regex' => 'Số điện thoại không đúng định dạng.',
            'phone.unique' => 'Số điện thoại đã được sử dụng.',
        ];
    }
}
