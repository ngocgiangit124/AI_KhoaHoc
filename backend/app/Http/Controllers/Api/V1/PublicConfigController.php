<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/config/public (host api, public) — api-contract §2.1.
 *
 * Chỉ trả allowlist khoá tường minh — KHÔNG BAO GIỜ trả nguyên `config('features')`
 * hay config khác (S22).
 */
class PublicConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'referral_code_enabled' => (bool) config('features.referral_code'),
            'quiz_time_limit_enabled' => (bool) config('features.quiz_time_limit'),
            'paid_checkout_enabled' => (bool) config('features.paid_checkout'),
            'otp' => [
                'ttl_minutes' => config('auth.otp.ttl_minutes'),
                'resend_cooldown_seconds' => config('auth.otp.cooldown_seconds'),
            ],
            'grades' => range(6, 12),
            'captcha_site_key' => config('services.turnstile.site_key') ?: null,
            'policy_version' => config('privacy.policy_version'),
            // ADR-006: deprecated, chỉ để FE GỢI Ý khối phụ huynh; không bao giờ bắt buộc.
            'parent_consent_age' => (int) config('privacy.parent_contact_suggest_age'),
            'parent_contact_required' => false,
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
