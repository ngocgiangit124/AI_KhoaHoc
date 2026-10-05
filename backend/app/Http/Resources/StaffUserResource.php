<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\Staff\StaffSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hồ sơ nhân sự (admin/quản lý trang/giáo viên) trả cho trang quản trị — api-contract §2.5.
 * `permissions` chỉ để ẩn/hiện UI; quyền thật luôn do Policy/middleware ở backend quyết định.
 *
 * @mixin User
 */
class StaffUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $this->role === UserRole::Admin;
        $isStaff = $this->isStaff();

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'must_change_password' => (bool) $this->must_change_password,
            // Ma trận quyền ADR-004 §3 (chỉ phục vụ UI).
            'permissions' => [
                'manage_system' => $isAdmin,
                'manage_subjects' => $isStaff,
                'manage_all_courses' => $isStaff,
                'manage_coupons' => $isStaff,
                'view_orders' => $isStaff,
                'export_orders' => $isStaff,
                'export_orders_with_contact' => $isAdmin,
            ],
        ];

        if ($request->hasSession()) {
            $expiry = StaffSession::absoluteExpiry($request->session());

            $data['session'] = [
                'idle_timeout_minutes' => (int) config('auth.staff.idle_minutes'),
                'expires_at' => $expiry !== null ? now()->setTimestamp($expiry)->toIso8601String() : null,
            ];
        }

        return $data;
    }
}
