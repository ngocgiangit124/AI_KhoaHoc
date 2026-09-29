<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\VerifyStaffMfaRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\OtpService;
use Illuminate\Validation\ValidationException;

/**
 * `POST /admin/auth/mfa/verify` (host admin-api — api-contract §2.5, tasks.md
 * T28). Chỉ có tác dụng cho phiên đang "chờ MFA" (Admin/Quản lý trang khi
 * `features.staff_mfa` bật — `Admin\Auth\LoginController`); Giáo Viên gọi
 * route này (không có mã nào đang chờ) sẽ luôn nhận lỗi mã không đúng.
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function verify(VerifyStaffMfaRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->otpService->verify($user, OtpPurpose::StaffLoginMfa, (string) $request->validated('code'));
        } catch (ValidationException $e) {
            $this->auditLogger->log('staff.mfa_failed', $user);

            throw $e;
        }

        $request->session()->put('staff_mfa_passed', true);

        $this->auditLogger->log('staff.mfa_verified', $user);

        return new UserResource($user);
    }
}
