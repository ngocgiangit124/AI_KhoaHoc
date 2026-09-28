<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/otp/verify (US-001, api-contract §2.2). `code` chỉ kiểm ĐỊNH
 * DẠNG (6 số) ở tầng này — so mã thật diễn ra nguyên tử trong `OtpService`
 * (S9).
 */
class VerifyOtpRequest extends FormRequest
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
