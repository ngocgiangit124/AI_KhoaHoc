<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/account/delete (api-contract §2.8.5). Chỉ mã OTP: xoá tài khoản không cần mật khẩu (ADR-006 mục 6).
 */
class ConfirmAccountDeletionRequest extends FormRequest
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
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã xác nhận.',
            'code.regex' => 'Mã xác nhận gồm đúng 6 chữ số.',
        ];
    }
}
