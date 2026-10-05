<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Services\Auth\Staff\StaffAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function verify(VerifyOtpRequest $request, StaffAuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $auth->verifyMfa($user, $request->validated()['code'], $request);

        return response()->json([
            'mfa_required' => false,
            'user' => (new StaffUserResource($user->refresh()))->resolve($request),
        ]);
    }

    public function resend(Request $request, StaffAuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resendAt = $auth->resendMfa($user, $request);

        return response()->json(['resend_available_at' => $resendAt->toIso8601String()], 202);
    }
}
