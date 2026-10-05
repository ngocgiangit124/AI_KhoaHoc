<?php

namespace App\Http\Requests\Admin\Staff;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Tạo tài khoản staff (US-016 AC1/AC2): họ tên + email + vai trò (không nhận `hoc_sinh`). */
class StaffStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-system');
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('name'))) {
            $merge['name'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('name')));
        }

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[<>\p{Cc}]/u', $value) === 1)) {
                        $fail('Họ tên chỉ được chứa văn bản thuần, không có thẻ HTML hay ký tự điều khiển.');
                    }
                },
            ],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class, 'email')],
            'role' => ['required', 'string', Rule::in(['admin', 'quan_ly_trang', 'giao_vien'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ tên.',
            'name.max' => 'Họ tên tối đa 100 ký tự.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'email.max' => 'Email tối đa 254 ký tự.',
            'email.unique' => 'Email đã được sử dụng.',
            'role.required' => 'Vui lòng chọn vai trò.',
            'role.in' => 'Vai trò chỉ nhận admin, quan_ly_trang hoặc giao_vien.',
        ];
    }
}
