<?php

namespace App\Http\Requests\Learn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quyền do LessonAccessService kiểm (đúng mã COURSE_NOT_OWNED). `position_seconds` vượt thời lượng được kẹp ở server
 * (ProgressService) vì thời lượng nằm trong DB, không tin client.
 */
class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'watched_delta_seconds' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }
}
