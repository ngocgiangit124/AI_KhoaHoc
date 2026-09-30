<?php

namespace App\Http\Requests\Admin;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/enrollment-requests/{enrollment}/reject` (api-contract §2.5,
 * US-012 AC3): "reason ≤ 1000 văn bản thuần". Phân quyền chạy TRƯỚC ở
 * middleware `can:decide,enrollment` (routes/admin.php, mẫu T06) — không gọi
 * `authorize()` trùng lặp ở đây.
 */
class RejectEnrollmentRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000', new PlainText],
        ];
    }
}
