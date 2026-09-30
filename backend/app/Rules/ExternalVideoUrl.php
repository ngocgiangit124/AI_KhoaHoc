<?php

namespace App\Rules;

use App\Support\ExternalVideoLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * S13 — link video ngoài chỉ nhận YouTube/Vimeo qua https, host whitelist,
 * ID khớp regex chặt (xem {@see ExternalVideoLink}). Host lạ, `javascript:`,
 * `data:`, http... → 422.
 */
class ExternalVideoUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (ExternalVideoLink::parse($value) === null) {
            $fail('Link video không hợp lệ. Chỉ hỗ trợ liên kết https của YouTube hoặc Vimeo.');
        }
    }
}
