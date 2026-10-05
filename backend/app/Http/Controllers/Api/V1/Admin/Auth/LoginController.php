<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\StaffLoginRequest;
use App\Http\Resources\StaffUserResource;
use App\Services\Auth\Staff\StaffAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoginController extends Controller
{
    public function store(StaffLoginRequest $request, StaffAuthService $auth): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new DomainException('ORIGIN_NOT_ALLOWED', 'Nguồn gọi không được phép.', 400);
        }

        $validated = $request->validated();

        $result = $auth->login($validated['login'], $validated['password'], $request);

        if ($result['mfa_required']) {
            return response()->json([
                'mfa_required' => true,
                'resend_available_at' => $result['resend_available_at']?->toIso8601String(),
            ]);
        }

        return response()->json([
            'mfa_required' => false,
            'user' => (new StaffUserResource($result['user']))->resolve($request),
        ]);
    }

    public function destroy(Request $request, StaffAuthService $auth): Response
    {
        if ($request->hasSession()) {
            $auth->logout($request);
        }

        return response()->noContent();
    }
}
