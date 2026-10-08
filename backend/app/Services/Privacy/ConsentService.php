<?php

namespace App\Services\Privacy;

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Ghi bằng chứng đồng ý của CHÍNH học sinh (S7). ADR-006: không có đồng ý của phụ huynh.
 */
class ConsentService
{
    /**
     * `true` khi đồng ý `terms` HOẶC `privacy_policy` mới nhất (chưa thu hồi) của học sinh có `policy_version` khác phiên bản
     * hiện hành (api-contract §2.8.1). FE chỉ hiện banner, không chặn học hay mua. Loại chưa có đồng ý nào thì không tính.
     */
    public function needsPolicyAcceptance(User $user): bool
    {
        $current = (string) config('privacy.policy_version');

        $rows = Consent::query()
            ->where('user_id', $user->getKey())
            ->whereIn('type', [ConsentType::Terms->value, ConsentType::PrivacyPolicy->value])
            ->whereNull('revoked_at')
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get(['id', 'type', 'policy_version']);

        foreach ($rows->groupBy(fn (Consent $c) => $c->type->value) as $perType) {
            if ((string) $perType->first()->policy_version !== $current) {
                return true;
            }
        }

        return false;
    }

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
