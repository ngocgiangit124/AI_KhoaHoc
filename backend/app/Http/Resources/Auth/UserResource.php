<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Object phẳng (api-contract §1.4) trả cho `POST /auth/register`,
 * `POST /auth/login` — không bao giờ chứa `password`/`current_session_id`
 * (User::$hidden — S17) hay `parent_phone`/`parent_email` thô (chỉ MeResource
 * mới trả bản đã che).
 *
 * @property-read User $resource
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
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
