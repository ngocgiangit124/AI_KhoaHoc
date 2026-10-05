<?php

namespace App\Http\Requests\Admin\Enrollment;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EnrollmentRequestIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAnyRequests', Enrollment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Không dùng `exists`: giáo viên không phụ trách phải nhận 403, không dò được khóa có tồn tại.
            'course_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['pending_approval', 'active', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.integer' => 'Mã khóa học không hợp lệ.',
            'course_id.min' => 'Mã khóa học không hợp lệ.',
            'status.in' => 'Trạng thái không hợp lệ.',
            'per_page.integer' => 'Số dòng mỗi trang không hợp lệ.',
            'per_page.min' => 'Số dòng mỗi trang phải từ 1 đến 100.',
            'per_page.max' => 'Số dòng mỗi trang phải từ 1 đến 100.',
        ];
    }
}
