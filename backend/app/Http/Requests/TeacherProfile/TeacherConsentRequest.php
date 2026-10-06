<?php

namespace App\Http\Requests\TeacherProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Giáo viên đồng ý công khai (US-020 BR4). CHỈ Gate `own-teacher-profile`: Admin/QLT không có đường đồng ý thay.
 * `version` phải bằng `consent.current_version` vừa hiển thị (lệch → 409 CONSENT_VERSION_CHANGED ở service).
 */
class TeacherConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('own-teacher-profile');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Thiếu phiên bản nội dung đồng ý.',
            'version.string' => 'Phiên bản nội dung đồng ý không hợp lệ.',
            'version.max' => 'Phiên bản nội dung đồng ý không hợp lệ.',
        ];
    }
}
