<?php

namespace App\Http\Middleware;

use App\Enums\ParentConsentStatus;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `parent.consent` — chặn checkout khi học sinh thuộc diện cần phụ huynh xác nhận mà chưa có
 * (US-017) → 403 `PARENT_CONSENT_REQUIRED`.
 *
 * Chỉ có hiệu lực khi `features.parent_consent_enforced` bật (mặc định TẮT: PO tạm bỏ ngưỡng tuổi phụ huynh ở v1,
 * mọi HS đều qua). Khi bật: chỉ cho qua `parent_consent_status` ∈ {not_required, granted}; pending/revoked bị chặn.
 * Luồng xác nhận đầy đủ, ngưỡng tuổi và nội dung pháp lý ở T29 (sẽ bật cờ).
 */
class EnsureParentConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.parent_consent_enforced')) {
            return $next($request);
        }

        $user = $request->user();

        $allowed = $user !== null && in_array($user->parent_consent_status, [ParentConsentStatus::NotRequired, ParentConsentStatus::Granted], true);

        if (! $allowed) {
            throw new DomainException(
                code: 'PARENT_CONSENT_REQUIRED',
                message: 'Bạn cần có xác nhận của phụ huynh trước khi thanh toán.',
                status: 403,
            );
        }

        return $next($request);
    }
}
