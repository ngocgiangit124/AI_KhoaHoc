<?php

namespace App\Http\Resources\Admin\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * GET /admin/auth/me (T28, api-contract §2.5): "user + role + quyền UI".
 *
 * Hợp đồng không liệt kê tên field cụ thể cho "quyền UI" — `permissions` chỉ
 * gồm khả năng đã có Gate thật (`manage-system`, ADR-004 §3); KHÔNG bịa thêm
 * quyền cho tính năng chưa tồn tại (T08+). Đổi/thêm khoá là thay đổi tương
 * thích ở v1 (api-contract §1.1).
 *
 * @property-read User $resource
 *
 * @mixin User
 */
class StaffMeResource extends JsonResource
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
            'must_change_password' => $this->must_change_password,
            'permissions' => [
                'manage_system' => Gate::forUser($this->resource)->allows('manage-system'),
            ],
        ];
    }
}
