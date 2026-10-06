<?php

namespace App\Http\Requests\TeacherProfile;

/**
 * Tải ảnh đại diện giáo viên (api-contract §4): jpg/png/webp, ≤ 2 MB, ≤ 4000x4000. Không nhận SVG/GIF/HTML. Việc cắt
 * vuông, thu nhỏ ≤ 800px, mã hoá lại WebP và bỏ EXIF do `ImageUploadService` làm (lớp phòng thủ thứ hai).
 */
class TeacherAvatarRequest extends TeacherProfileRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'max:2048',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Vui lòng chọn ảnh đại diện.',
            'avatar.file' => 'Ảnh đại diện không hợp lệ.',
            'avatar.uploaded' => 'Không tải được ảnh đại diện (tối đa 2 MB).',
            'avatar.mimes' => 'Ảnh đại diện chỉ nhận JPG, PNG hoặc WebP.',
            'avatar.mimetypes' => 'Ảnh đại diện chỉ nhận JPG, PNG hoặc WebP.',
            'avatar.max' => 'Ảnh đại diện tối đa 2 MB.',
            'avatar.dimensions' => 'Ảnh đại diện tối đa 4000x4000 px.',
        ];
    }
}
