<?php

namespace App\Http\Requests\Admin\Enrollment;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Duyệt/từ chối: quyền kiểm theo `$enrollment->course` (giáo viên chỉ khóa mình phụ trách) TRƯỚC validate.
 */
class DecideEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrollment = $this->route('enrollment');

        return $enrollment instanceof Enrollment && Gate::allows('decide', $enrollment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($value !== strip_tags($value) || preg_match('/[<>\p{Cc}]/u', str_replace(["\n", "\r", "\t"], '', $value)) === 1)) {
                    $fail('Lý do chỉ được là văn bản thuần, không chứa thẻ HTML.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.string' => 'Lý do không hợp lệ.',
            'reason.max' => 'Lý do tối đa 1.000 ký tự.',
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
