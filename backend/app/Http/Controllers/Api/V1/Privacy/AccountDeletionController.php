<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\ConfirmAccountDeletionRequest;
use App\Models\User;
use App\Services\Privacy\AccountAnonymizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Xoá (ẩn danh hoá) tài khoản của chính học sinh bằng OTP email (api-contract §2.8.5).
 */
class AccountDeletionController extends Controller
{
    public function sendOtp(Request $request, AccountAnonymizer $anonymizer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sent = $anonymizer->sendOtp($user);
        $timezone = (string) config('privacy.age_timezone');

        return response()->json([
            'resend_available_at' => $sent['resend_available_at']->setTimezone($timezone)->toIso8601String(),
            'destination_masked' => $sent['destination_masked'],
        ], 202);
    }

    public function confirm(ConfirmAccountDeletionRequest $request, AccountAnonymizer $anonymizer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $anonymizer->confirm($user, (string) $request->validated('code'));

        // Phiên khác đã bị huỷ trong service; phiên hiện tại thành phiên khách mới (Set-Cookie trong cùng response).
        // `SessionGuard::logout()` xoay `remember_token` (và ghi lại DB) nếu model trong bộ nhớ còn token cũ: xoá trước để
        // không ghi token mới vào dòng đã ẩn danh hoá.
        $user->setRememberToken('');
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Tài khoản của bạn đã được xoá.']);
    }
}
