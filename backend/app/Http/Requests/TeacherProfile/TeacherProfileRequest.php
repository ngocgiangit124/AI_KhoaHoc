<?php

namespace App\Http\Requests\TeacherProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Nền chung cho request hồ sơ giáo viên (US-020). `authorize()` kiểm Gate TRƯỚC validate (403 không lộ lỗi trường).
 * Route `/admin/me/teacher-profile/*` (không có `{user}`) dùng Gate `own-teacher-profile` (chỉ giáo viên); route
 * `/admin/teacher-profiles/{user}/*` dùng `manage-teacher-profiles` (admin, quản lý trang).
 */
abstract class TeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->route('user') === null ? 'own-teacher-profile' : 'manage-teacher-profiles');
    }
}
