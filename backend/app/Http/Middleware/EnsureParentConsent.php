<?php

namespace App\Http\Middleware;

use App\Enums\ParentConsentStatus;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `parent.consent` (api-contract §1.7 `403 PARENT_CONSENT_REQUIRED`,
 * US-017 BR6). BẢN TẠM theo tasks.md T18: "middleware `parent.consent` (tạm
 * cho qua nếu `parent_consent_status` ∈ {not_required, granted}; luồng đầy
 * đủ ở T29)" — T14 hiện thực bản tạm này trước (cần ngay cho đăng ký khóa
 * miễn phí), T18 (checkout) dùng lại NGUYÊN alias này, không viết lại.
 *
 * KHÔNG xử lý gửi lại email xin xác nhận / mốc thời gian rút lại đồng ý theo
 * thời gian thực — thuộc `ParentConsentService` (T29). Không sửa file này ở
 * T29 nếu chỉ để đổi hành vi middleware; chỉ mở rộng nếu quy tắc chặn thay
 * đổi.
 */
class EnsureParentConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $status = $request->user()?->parent_consent_status;

        $allowed = $status === ParentConsentStatus::NotRequired
            || $status === ParentConsentStatus::Granted;

        if (! $allowed) {
            throw new DomainException(
                code: 'PARENT_CONSENT_REQUIRED',
                message: 'Cần có xác nhận của phụ huynh trước khi tiếp tục.',
                status: 403,
            );
        }

        return $next($request);
    }
}
