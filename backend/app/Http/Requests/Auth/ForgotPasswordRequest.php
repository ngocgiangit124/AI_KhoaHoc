<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\DomainException;
use App\Services\Auth\Captcha\CaptchaVerifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * api-contract §2.2 — POST /auth/password/forgot. Captcha kiểm TRƯỚC mọi rule (US-015 BR6), giống đăng ký.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->hasSession()) {
            throw new DomainException('ORIGIN_NOT_ALLOWED', 'Nguồn gọi không được phép.', 400);
        }

        $token = $this->input('captcha_token');

        if (! app(CaptchaVerifier::class)->verify(is_string($token) ? $token : null, $this->ip())) {
            throw new DomainException(
                code: 'CAPTCHA_FAILED',
                message: 'Xác minh captcha không thành công. Vui lòng thử lại.',
                status: 422,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:254'],
            'captcha_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Vui lòng nhập email hoặc số điện thoại.',
        ];
    }
}
