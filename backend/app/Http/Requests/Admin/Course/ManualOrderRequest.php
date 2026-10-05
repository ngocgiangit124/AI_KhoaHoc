<?php

namespace App\Http\Requests\Admin\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** PATCH /admin/courses/{course}/manual-order — `{manual_order: int|null}` (bắt buộc có khoá), chỉ staff. */
class ManualOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageOrder', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['manual_order' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000']];
    }

    public function messages(): array
    {
        return ['manual_order.present' => 'Vui lòng gửi manual_order (số nguyên hoặc null).'];
    }
}
