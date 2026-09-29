<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\StaffLoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\OtpService;
use App\Services\Auth\StaffAuthService;
use App\Services\Auth\StaffDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * `POST /admin/auth/login`, `POST /admin/auth/logout` (host admin-api —
 * api-contract §2.5, tasks.md T28).
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly StaffAuthService $staffAuthService,
        private readonly OtpService $otpService,
        private readonly StaffDeviceService $staffDeviceService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(StaffLoginRequest $request): UserResource|JsonResponse
    {
        $user = $this->staffAuthService->authenticate(
            (string) $request->validated('login'),
            (string) $request->validated('password'),
        );

        Auth::login($user);
        $request->session()->regenerate();

        // README §3.2 — phiên "thật sự" bắt đầu ngay khi mật khẩu đúng (KHÔNG
        // đợi MFA), để `staff.idle` (T28) tính đúng hạn tối đa 12h kể cả khi
        // người dùng cố ý trì hoãn bước MFA.
        $now = now()->timestamp;
        $request->session()->put([
            'staff_login_at' => $now,
            'staff_last_activity' => $now,
        ]);

        $mfaRequired = $this->requiresMfa($user->role);

        $request->session()->put('staff_mfa_passed', ! $mfaRequired);

        if ($mfaRequired) {
            $this->otpService->send($user, OtpPurpose::StaffLoginMfa, 'email');

            $this->auditLogger->log('staff.login', $user, ['mfa_required' => true]);

            return response()->json(['mfa_required' => true]);
        }

        if ($user->role === UserRole::Teacher) {
            $this->staffDeviceService->recordLoginAndWarnIfNew($user, $this->deviceId($request));
        }

        $this->auditLogger->log('staff.login', $user, ['mfa_required' => false]);

        return new UserResource($user);
    }

    /**
     * Ngoại lệ duy nhất (api-contract §1.3): chỉ cần `auth:sanctum` — đăng
     * xuất phải luôn thực hiện được, kể cả tài khoản vừa bị khoá hoặc chưa
     * qua MFA/đổi mật khẩu.
     */
    public function destroy(Request $request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // Ghi audit TRƯỚC khi đăng xuất — `AuditLogger::log()` lấy actor từ
        // `Auth::user()` (chính người đang đăng xuất).
        if ($user !== null) {
            $this->auditLogger->log('staff.logout', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function requiresMfa(UserRole $role): bool
    {
        if (! (bool) config('features.staff_mfa')) {
            return false;
        }

        return $role === UserRole::Admin || $role === UserRole::PageManager;
    }

    /**
     * `X-Device-Id` (ADR-003 §2 — UUID v4 do frontend sinh). Định dạng sai/
     * thiếu → bỏ qua (chỉ ảnh hưởng cảnh báo thiết bị mới, không chặn đăng
     * nhập).
     */
    private function deviceId(Request $request): ?string
    {
        $deviceId = $request->header('X-Device-Id');

        if (! is_string($deviceId) || ! Str::isUuid($deviceId)) {
            return null;
        }

        return $deviceId;
    }
}
