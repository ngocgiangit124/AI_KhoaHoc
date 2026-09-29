<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /auth/otp/send (US-001, api-contract §2.2). `channel` phải nằm trong
 * `config('auth.otp.channels')` — production MVP chỉ `email`, gửi `sms` sẽ
 * nhận 422 (chưa có nhà cung cấp SMS thật — S9/S11).
 */
class SendOtpRequest extends FormRequest
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
            'channel' => ['required', 'string', Rule::in((array) config('auth.otp.channels'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel.in' => 'Kênh gửi mã OTP không hợp lệ.',
        ];
    }
}
