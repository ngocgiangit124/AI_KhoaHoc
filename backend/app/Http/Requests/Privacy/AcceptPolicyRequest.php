<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/consents/accept (api-contract §2.8.3).
 */
class AcceptPolicyRequest extends FormRequest
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
            'policy_version' => ['required', 'string', 'max:20'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Bạn cần đồng ý với Điều khoản sử dụng.',
            'accept_privacy.accepted' => 'Bạn cần đồng ý với Chính sách bảo mật.',
        ];
    }
}
