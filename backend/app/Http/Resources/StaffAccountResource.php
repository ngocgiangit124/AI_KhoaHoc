<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tài khoản staff cho màn quản lý (US-016). Không bao giờ có mật khẩu/băm; `is_self` để UI ẩn nút tự khoá.
 *
 * @mixin User
 */
class StaffAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'must_change_password' => (bool) $this->must_change_password,
            'last_login_at' => self::iso($this->last_login_at),
            'password_changed_at' => self::iso($this->password_changed_at),
            'created_at' => self::iso($this->created_at),
            'is_self' => $request->user()?->getKey() === $this->getKey(),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : null;
    }
}
