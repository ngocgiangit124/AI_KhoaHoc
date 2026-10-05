<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function request(ForgotPasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $login = $request->validated()['login'];

        // Hạn mức theo tài khoản chỉ tính khi đã qua captcha (FormRequest chạy trước).
        $passwords->enforceForgotLimits($login);

        // Gửi mã SAU response: thời gian + nội dung phản hồi giống hệt dù tài khoản có tồn tại hay không (BR2).
        defer(fn () => $passwords->sendResetCode($login));

        return response()->json([
            'message' => PasswordService::MESSAGE_FORGOT,
            // Cố định cho mọi yêu cầu (không lộ gì): khớp cooldown của limiter `password-reset`.
            'resend_available_at' => now()->addSeconds((int) config('auth.otp.cooldown_seconds'))->toIso8601String(),
        ], 202);
    }

    public function reset(ResetPasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        $data = $request->validated();

        $passwords->reset($data['login'], $data['code'], $data['password']);

        return response()->json(['message' => PasswordService::MESSAGE_RESET_DONE]);
    }
}
