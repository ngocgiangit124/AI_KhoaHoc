<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\UpdateStaffPasswordRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * `PUT /admin/auth/password` (host admin-api — api-contract §2.5, tasks.md
 * T28). Bắt buộc gọi khi `must_change_password` (route này là lối thoát DUY
 * NHẤT khỏi `staff.password_fresh` — xem `routes/admin.php`), cũng dùng để
 * đổi mật khẩu tự nguyện. Route VẪN đòi `staff.mfa_passed` (R1,
 * review-T28.md) — Admin/QLT phải qua MFA trước khi đổi được mật khẩu, chỉ
 * riêng `staff.password_fresh` là được miễn.
 *
 * "Huỷ phiên khác" (api-contract §2.5) đến từ chính việc đổi
 * `users.password`: middleware `Laravel\Sanctum\Http\Middleware\AuthenticateSession`
 * (alias `staff.session`, gắn trên toàn bộ route đã đăng nhập của host
 * admin-api) tự phát hiện phiên khác còn giữ hash mật khẩu CŨ ở request tiếp
 * theo của họ và đăng xuất — không cần gọi `Auth::logoutOtherDevices()` (hàm
 * đó dành cho use-case khác: giữ NGUYÊN mật khẩu nhưng ép đổi hash để đăng
 * xuất các thiết bị còn lại).
 */
class PasswordController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(UpdateStaffPasswordRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không đúng.'],
            ]);
        }

        // Không nằm trong $fillable (S17) — chỉ Service/Controller chuyên
        // trách được đổi.
        $user->forceFill([
            'password' => Hash::make((string) $request->validated('password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->auditLogger->log('staff.password_changed', $user);

        return new UserResource($user);
    }
}
