<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/login (US-001, api-contract §2.2) — `login` là email HOẶC SĐT
 * (BR1). Không xác định trước cái nào để không tiết lộ định dạng tài khoản
 * tồn tại (BR5) — `LoginService` tự nhận diện.
 */
class LoginRequest extends FormRequest
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
            'login' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string'],
            // Chưa dùng ở T03 (xem RegisterRequest::rules()).
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
