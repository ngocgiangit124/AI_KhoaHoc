<?php

namespace App\Http\Requests\Admin\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Danh sách tài khoản staff (US-016): chỉ admin (`manage-system`), kiểm quyền trước validate. */
class StaffIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(['admin', 'quan_ly_trang', 'giao_vien'])],
            'status' => ['nullable', 'string', Rule::enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Vai trò lọc chỉ nhận admin, quan_ly_trang, giao_vien.',
            'status.enum' => 'Trạng thái lọc chỉ nhận active hoặc locked.',
            'per_page.in' => 'Số dòng mỗi trang chỉ nhận 25 hoặc 50.',
            'q.max' => 'Từ khoá tối đa 100 ký tự.',
        ];
    }

    public function roleFilter(): ?UserRole
    {
        return $this->filled('role') ? UserRole::from($this->string('role')->toString()) : null;
    }
}
