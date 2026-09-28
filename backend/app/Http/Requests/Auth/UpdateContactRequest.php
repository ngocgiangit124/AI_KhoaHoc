<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\PhoneNumber;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /auth/contact (US-001, api-contract §2.2) — đổi email và/hoặc SĐT của
 * chính học sinh đang đăng nhập. Không cần captcha (đã là request đã xác
 * thực, khác `RegisterRequest` — M4 chỉ áp dụng cho luồng công khai).
 */
class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'email' => [
                'sometimes', 'nullable', 'string', 'email:rfc', 'max:254', 'ascii',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($userId),
                function ($attribute, $value, $fail): void {
                    if ($value !== null && (! is_string($value) || ! PhoneNumber::isValidInput($value))) {
                        $fail('Số điện thoại không đúng định dạng Việt Nam.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email đã được sử dụng.',
            'phone.unique' => 'Số điện thoại đã được sử dụng.',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if (! filled($this->input('email')) && ! filled($this->input('phone'))) {
                $validator->errors()->add('email', 'Vui lòng nhập email hoặc số điện thoại mới.');
            }
        });
    }
}
