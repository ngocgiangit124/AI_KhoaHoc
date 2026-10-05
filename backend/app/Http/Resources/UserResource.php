<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hồ sơ học sinh trả về sau đăng ký/đăng nhập. KHÔNG có thông tin phụ huynh
 * (chỉ `/auth/me` trả bản đã che — api-contract §2.2).
 *
 * @mixin User
 */
class UserResource extends JsonResource
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
            'phone' => $this->phone,
            'role' => $this->role->value,
            'grade_level' => $this->grade_level,
            'is_verified' => $this->isVerified(),
            'parent_consent_status' => $this->parent_consent_status->value,
        ];
    }
}
