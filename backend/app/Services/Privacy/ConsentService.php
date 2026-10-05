<?php

namespace App\Services\Privacy;

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Ghi bằng chứng đồng ý của CHÍNH học sinh (S7). Đồng ý của phụ huynh thuộc T29.
 */
class ConsentService
{
    /**
     * Ghi đồng ý điều khoản + chính sách bảo mật tại thời điểm đăng ký.
     * Gọi trong cùng transaction tạo user.
     */
    public function recordSelfConsentAtRegistration(User $user, Request $request): void
    {
        $now = now();
        $ip = $request->ip();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);

        foreach ([ConsentType::Terms, ConsentType::PrivacyPolicy] as $type) {
            Consent::create([
                'user_id' => $user->id,
                'type' => $type,
                'policy_version' => (string) config('privacy.policy_version'),
                'granted_by' => Consent::GRANTED_BY_SELF,
                'channel' => Consent::CHANNEL_WEB_FORM,
                'granted_at' => $now,
                'ip' => $ip,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ]);
        }
    }
}
