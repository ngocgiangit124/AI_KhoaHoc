<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeStaffPasswordRequest extends FormRequest
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
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:128', Password::defaults(), 'different:current_password'],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'password_confirmation.required' => 'Vui lòng nhập lại mật khẩu mới.',
            'password_confirmation.same' => 'Mật khẩu xác nhận không khớp.',
        ];
    }
}
