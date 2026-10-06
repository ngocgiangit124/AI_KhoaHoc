<?php

namespace App\VideoLab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVideoRequest extends FormRequest
{
    /** Xác thực bằng middleware AccessKey. */
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
            'title' => ['required', 'string', 'max:255'],
            'max_bytes' => ['sometimes', 'integer', 'min:1'],
            'created_by_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
