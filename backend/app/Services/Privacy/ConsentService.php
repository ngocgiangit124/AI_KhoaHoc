<?php

namespace App\Services\Privacy;

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Ghi bằng chứng đồng ý xử lý dữ liệu (data-model §3.1, S7, US-001/US-017).
 * Duy nhất Service này được tạo bản ghi `consents` — không sửa/xoá (bất biến
 * theo thiết kế, không cần chặn ở Model vì chỉ có action "tạo" và "thu hồi"
 * qua `revoke()`, cả hai đều do chính Service này thực hiện).
 */
class ConsentService
{
    /**
     * @param  'self'|'parent'  $grantedBy
     * @param  'web_form'|'email_otp'|'email_link'  $channel
     */
    public function grant(
        User $user,
        ConsentType $type,
        string $grantedBy,
        string $channel,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $destinationMasked = null,
    ): Consent {
        return Consent::query()->create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'policy_version' => (string) config('privacy.policy_version'),
            'granted_by' => $grantedBy,
            'channel' => $channel,
            'destination_masked' => $destinationMasked,
            'granted_at' => now(),
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 255, '') : null,
        ]);
    }

    public function revoke(Consent $consent): void
    {
        $consent->forceFill(['revoked_at' => now()])->save();
    }
}
