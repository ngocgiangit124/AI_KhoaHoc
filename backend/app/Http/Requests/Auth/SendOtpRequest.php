<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /auth/otp/send — kênh phải nằm trong `config('auth.otp.channels')`
 * (production MVP: chỉ `email`; `sms` → 422, S9).
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
            'channel' => ['sometimes', 'string', Rule::in((array) config('auth.otp.channels'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['channel.in' => 'Kênh gửi mã không được hỗ trợ.'];
    }

    public function channel(): string
    {
        return (string) ($this->validated()['channel'] ?? 'email');
    }
}
