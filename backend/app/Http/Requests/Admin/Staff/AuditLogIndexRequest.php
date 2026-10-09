<?php

namespace App\Http\Requests\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Xem nhật ký thao tác (chỉ đọc, US-016 AC9): chỉ admin. */
class AuditLogIndexRequest extends FormRequest
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
            'action' => ['nullable', 'string', 'max:60'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:40'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.max' => 'Hành động tối đa 60 ký tự.',
            'actor_id.integer' => 'Người thực hiện không hợp lệ.',
            'subject_id.integer' => 'Đối tượng không hợp lệ.',
            'from.date_format' => 'Ngày bắt đầu phải có dạng YYYY-MM-DD.',
            'to.date_format' => 'Ngày kết thúc phải có dạng YYYY-MM-DD.',
            'to.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
            'page.integer' => 'Trang không hợp lệ.',
            'page.min' => 'Trang không hợp lệ.',
            'page.max' => 'Trang không hợp lệ.',
            'per_page.in' => 'Số dòng mỗi trang chỉ nhận 25, 50 hoặc 100.',
        ];
    }
}
