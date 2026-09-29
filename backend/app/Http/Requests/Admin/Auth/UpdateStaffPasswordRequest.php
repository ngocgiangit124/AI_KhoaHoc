<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * PUT /admin/auth/password (T28, api-contract §2.5) — bắt buộc khi
 * `must_change_password`; cũng dùng để đổi mật khẩu tự nguyện. Hình dạng
 * request giống hệt endpoint tương đương của học sinh (`PUT /auth/password`,
 * api-contract §2.2): `current_password`, `password` (confirmed) — không bịa
 * field mới, chỉ theo đúng mẫu đã có trong hợp đồng cho hành động cùng loại.
 */
class UpdateStaffPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::default()],
        ];
    }
}
