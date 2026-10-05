<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bản ghi nhật ký (chỉ đọc). `changes` đã được AuditLogger loại PII/secret lúc ghi.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $actor */
        $actor = $this->resource->relationLoaded('actor') ? $this->resource->getRelation('actor') : null;

        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'actor_role' => $this->actor_role,
            'actor_name' => $actor?->name,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'changes' => $this->changes ?? (object) [],
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
