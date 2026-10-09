<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

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
            'login' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            // Chỉ nhận chuỗi; sai định dạng bị bỏ qua (chỉ dùng nhận diện thiết bị cho email cảnh báo).
            'device_id' => ['nullable', 'string'],
            // GL-A2: bắt buộc (kiểm trong service) khi tài khoản đã chạm ngưỡng đăng nhập sai; thiếu/sai → 422 CAPTCHA_*.
            'captcha_token' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Vui lòng nhập email.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ];
    }
}
