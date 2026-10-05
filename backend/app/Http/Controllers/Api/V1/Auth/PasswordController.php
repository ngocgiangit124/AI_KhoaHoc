<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, PasswordService $passwords): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $kept = $passwords->change($user, $data['current_password'], $data['password'], $request);

        return response()->json([
            'message' => PasswordService::MESSAGE_CHANGED,
            'session_kept' => $kept,
        ]);
    }
}
