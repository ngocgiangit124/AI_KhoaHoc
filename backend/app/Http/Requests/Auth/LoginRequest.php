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
            // M2 (review docs/security/review-T03-FW1.md) — CHỈ chấp nhận
            // ASCII cho `login` (rule `ascii` có sẵn của Laravel — 7-bit).
            // Chặn ngay ở đây (422, không chạm DB/Service) mọi biến thể
            // Unicode "trông giống" ASCII (full-width, ký tự có dấu tương
            // đương theo collation MySQL...) — email/SĐT hợp lệ của dự án
            // luôn thuần ASCII nên không mất khả năng đăng nhập hợp lệ nào.
            'login' => ['required', 'string', 'max:254', 'ascii'],
            'password' => ['required', 'string'],
            // Chưa dùng ở T03 (xem RegisterRequest::rules()).
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.ascii' => 'Thông tin đăng nhập hoặc mật khẩu không đúng.',
        ];
    }
}
