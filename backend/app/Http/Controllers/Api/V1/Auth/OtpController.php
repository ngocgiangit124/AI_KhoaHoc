<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    public function send(SendOtpRequest $request, OtpService $otp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resendAt = $otp->sendVerification($user, $request->channel());

        return response()->json(['resend_available_at' => $resendAt->toIso8601String()], 202);
    }

    public function verify(VerifyOtpRequest $request, OtpService $otp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verified = $otp->verifyAccount($user, $request->validated()['code']);

        // Ép 200: JsonResource tự trả 201 nếu model vừa được tạo trong chính tiến trình này.
        return (new UserResource($verified))->response()->setStatusCode(200);
    }
}
