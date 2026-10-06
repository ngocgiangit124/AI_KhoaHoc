<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `expected_total`: tổng tiền HS đã thấy ở preview (VND, số nguyên ≥ 0) — chống đổi giá âm thầm.
     * `gateway`: chỉ nhận cổng trong allowlist `payments.enabled_gateways`; bỏ trống = cổng đầu tiên.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_total' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'gateway' => ['sometimes', 'string', Rule::in((array) config('payments.enabled_gateways'))],
        ];
    }

    public function gateway(): string
    {
        $enabled = (array) config('payments.enabled_gateways');

        return (string) ($this->validated('gateway') ?? ($enabled[0] ?? ''));
    }
}
