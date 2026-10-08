<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/data-export (api-contract §2.8.4).
 */
class DataExportRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.'];
    }
}
