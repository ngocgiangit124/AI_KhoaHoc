<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Auth\ContactService;
use App\Services\Privacy\ParentNoticeSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `ParentContact` (api-contract §2.8.1): LUÔN là bản đã che, không bao giờ trả email/SĐT phụ huynh đầy đủ.
 *
 * @mixin User
 */
class ParentContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $email = is_string($this->parent_email) && $this->parent_email !== '' ? $this->parent_email : null;
        $phone = is_string($this->parent_phone) && $this->parent_phone !== '' ? $this->parent_phone : null;
        $optedOutAt = $this->parent_notice_opt_out_at;

        // Địa chỉ đã bị phụ huynh huỷ nhận (kể cả khi học sinh xoá rồi thêm lại) vẫn hiển thị là đã ngừng nhận.
        if ($email !== null && $optedOutAt === null) {
            $optedOutAt = app(ParentNoticeSuppression::class)->since($email);
        }

        return [
            'email_masked' => $email === null ? null : ContactService::maskEmail($email),
            'phone_masked' => $phone === null ? null : self::maskPhone($phone),
            'has_email' => $email !== null,
            'has_phone' => $phone !== null,
            'notices_enabled' => $email !== null && $optedOutAt === null && (bool) config('features.parent_notices'),
            'notices_opted_out_at' => $optedOutAt?->copy()->setTimezone((string) config('privacy.age_timezone'))->toIso8601String(),
        ];
    }

    /** Giữ 3 số cuối: `0912345789` → `*******789`. */
    private static function maskPhone(string $phone): string
    {
        $length = mb_strlen($phone);

        return $length <= 3 ? str_repeat('*', $length) : str_repeat('*', $length - 3).mb_substr($phone, -3);
    }
}
