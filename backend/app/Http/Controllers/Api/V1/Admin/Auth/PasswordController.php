<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\ChangeStaffPasswordRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Services\Auth\Staff\StaffAuthService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function update(ChangeStaffPasswordRequest $request, StaffAuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $auth->changePassword($user, $validated['current_password'], $validated['password'], $request);

        return response()->json([
            'user' => (new StaffUserResource($user->refresh()))->resolve($request),
        ]);
    }
}
