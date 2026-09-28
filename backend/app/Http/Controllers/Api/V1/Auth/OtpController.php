<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\Auth\MeResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/otp/send, POST /auth/otp/verify (US-001, api-contract §2.2).
 * Cả 2 route đều xác thực tài khoản (mục đích: verify_account) — reset mật
 * khẩu (T27), MFA staff (T28), xác nhận phụ huynh (T29) dùng `OtpPurpose`
 * khác qua các luồng riêng.
 */
class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otpService) {}

    public function send(SendOtpRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resendAvailableAt = $this->otpService->send(
            $user,
            OtpPurpose::VerifyAccount,
            (string) $request->validated('channel'),
        );

        return response()->json([
            'resend_available_at' => $resendAvailableAt->toIso8601String(),
        ], 202);
    }

    public function verify(VerifyOtpRequest $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        $this->otpService->verify($user, OtpPurpose::VerifyAccount, (string) $request->validated('code'));

        return new MeResource($user->refresh());
    }
}
