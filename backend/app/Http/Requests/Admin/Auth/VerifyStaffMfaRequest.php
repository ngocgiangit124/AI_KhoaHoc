<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/auth/mfa/verify (T28, api-contract §2.5). `code` chỉ kiểm ĐỊNH
 * DẠNG ở đây — so mã thật diễn ra nguyên tử trong `OtpService` (S9), giống
 * `App\Http\Requests\Auth\VerifyOtpRequest`.
 */
class VerifyStaffMfaRequest extends FormRequest
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
            'code.regex' => 'Mã OTP không đúng, vui lòng thử lại.',
        ];
    }
}
