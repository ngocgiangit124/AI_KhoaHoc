<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /admin/enrollment-requests` (api-contract §2.5, US-012 AC7): filter
 * `course_id?` tùy chọn. Phân quyền (GV chỉ khóa mình phụ trách — BR4/AC6)
 * chạy trong `EnrollmentRequestController::index()` qua
 * `EnrollmentPolicy::viewAnyRequests()` (cần biết course_id ĐÃ VALIDATE
 * trước, không đặt được ở middleware `can:` route như các action ghi).
 */
class EnrollmentRequestIndexRequest extends FormRequest
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
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ];
    }
}
