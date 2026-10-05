<?php

namespace App\Http\Requests\Admin\Content;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Xin phiên upload video. `filename` chỉ để hiển thị (không bao giờ làm đường dẫn); `size` ≤ `video.max_upload_mb`.
 * Hạn mức ngày và nhà cung cấp do VideoUploadService kiểm.
 */
class VideoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageContent', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $extensions = implode('|', array_map('preg_quote', (array) config('video.allowed_extensions')));

        return [
            'filename' => ['required', 'string', 'max:255', 'regex:/\.('.$extensions.')$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.((int) config('video.max_upload_mb') * 1024 * 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filename.required' => 'Vui lòng chọn file video.',
            'filename.regex' => 'Chỉ hỗ trợ file video: '.implode(', ', (array) config('video.allowed_extensions')).'.',
            'size.required' => 'Thiếu dung lượng file.',
            'size.integer' => 'Dung lượng file không hợp lệ.',
            'size.min' => 'File video rỗng.',
            'size.max' => 'Dung lượng video tối đa '.config('video.max_upload_mb').' MB.',
        ];
    }
}
