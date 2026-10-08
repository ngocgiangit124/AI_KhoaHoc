<?php

namespace App\Http\Resources;

use App\Models\Consent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Consent` (api-contract §2.8.1). KHÔNG có `ip`, `user_agent`, `destination_masked`: các giá trị đó chỉ nằm trong file xuất.
 *
 * @mixin Consent
 */
class ConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'policy_version' => $this->policy_version,
            'granted_by' => $this->granted_by,
            'channel' => $this->channel,
            'granted_at' => $this->granted_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_current_version' => $this->policy_version === (string) config('privacy.policy_version'),
        ];
    }
}
