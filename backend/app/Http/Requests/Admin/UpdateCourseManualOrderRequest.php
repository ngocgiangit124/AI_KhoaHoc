<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /admin/courses/{course}/manual-order` (US-002 BR6 "Nổi bật",
 * api-contract §2.5 — "int | null"). Phân quyền staff-only chạy TRƯỚC ở
 * middleware `can:updateManualOrder,course`.
 */
class UpdateCourseManualOrderRequest extends FormRequest
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
            'manual_order' => ['nullable', 'integer'],
        ];
    }
}
