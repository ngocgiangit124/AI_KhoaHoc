<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/auth/login (T28, api-contract §2.5) — `login` là email HOẶC
 * SĐT, cùng quy tắc với `App\Http\Requests\Auth\LoginRequest` (học sinh):
 * không xác định trước định dạng để không tiết lộ định dạng tài khoản tồn
 * tại (BR5) — `StaffAuthService` tự nhận diện.
 */
class StaffLoginRequest extends FormRequest
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
            // M2 (review docs/security/review-T03-FW1.md, áp dụng lại cho T28)
            // — chỉ ASCII, chặn biến thể Unicode "trông giống" (full-width...)
            // trước khi chạm DB/Service.
            'login' => ['required', 'string', 'max:254', 'ascii'],
            'password' => ['required', 'string'],
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
