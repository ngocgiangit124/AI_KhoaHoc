<?php

namespace App\Http\Requests\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Đổi vai trò staff: chỉ 3 vai trò staff. */
class StaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-system');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['admin', 'quan_ly_trang', 'giao_vien'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Vui lòng chọn vai trò.',
            'role.in' => 'Vai trò chỉ nhận admin, quan_ly_trang hoặc giao_vien.',
        ];
    }
}
