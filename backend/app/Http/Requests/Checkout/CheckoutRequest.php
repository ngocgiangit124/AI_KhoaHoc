<?php

namespace App\Http\Requests\Checkout;

use App\Rules\PlainText;
use App\Services\Orders\PaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** `\r\n` → `\n`, trim; chuỗi rỗng sau trim → null (api-contract §2.3.1). */
    protected function prepareForValidation(): void
    {
        $note = $this->input('customer_note');

        if (is_string($note)) {
            $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
            $this->merge(['customer_note' => $note === '' ? null : $note]);
        }

        foreach (['payment_method', 'gateway'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => mb_strtolower(trim($value))]);
            }
        }
    }

    /**
     * `expected_total`: tổng tiền HS đã thấy ở preview (VND, số nguyên ≥ 0) — chống đổi giá âm thầm.
     * `payment_method` (US-022): `manual` hoặc cổng trong allowlist `payments.enabled_gateways`; việc phương thức có đang BẬT hay
     * không do CheckoutService quyết (sau CHECKOUT_CHANGED/503). `gateway`: bí danh cũ (deprecated).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $enabled = (array) config('payments.enabled_gateways');

        return [
            'expected_total' => ['required', 'integer', 'min:0', 'max:2000000000'],
            // Danh sách rỗng (V1, chưa bật cổng): không ép `in` cho `gateway` — đơn 0đ không cần cổng; CheckoutService kiểm khi tổng > 0.
            // `manual` KHÔNG hợp lệ ở bí danh cũ (luôn 422 ở `gateway`); dùng `payment_method`.
            'gateway' => ['sometimes', 'string', Rule::notIn([PaymentMethods::MANUAL]), ...($enabled === [] ? [] : [Rule::in($enabled)])],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in([PaymentMethods::MANUAL, ...$enabled])],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:500', new PlainText(allowNewlines: true)],
            'replace_pending' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $method = $this->input('payment_method');
                $gateway = $this->input('gateway');

                if (is_string($method) && $method !== '' && is_string($gateway) && $gateway !== '' && $method !== $gateway) {
                    $validator->errors()->add('payment_method', 'Phương thức thanh toán không khớp.');
                }
            },
        ];
    }

    /** Phương thức do HS chọn (`payment_method` rồi đến `gateway`); null = dùng mặc định của server. */
    public function paymentMethod(): ?string
    {
        $method = $this->validated('payment_method') ?? $this->validated('gateway');

        return is_string($method) && $method !== '' ? $method : null;
    }

    public function customerNote(): ?string
    {
        $note = $this->validated('customer_note');

        return is_string($note) && $note !== '' ? $note : null;
    }

    public function replacePending(): bool
    {
        return (bool) $this->validated('replace_pending', false);
    }
}
