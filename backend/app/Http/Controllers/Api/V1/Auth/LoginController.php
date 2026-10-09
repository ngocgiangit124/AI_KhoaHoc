<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoginController extends Controller
{
    public function store(LoginRequest $request, LoginService $login): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new DomainException('ORIGIN_NOT_ALLOWED', 'Nguồn gọi không được phép.', 400);
        }

        $validated = $request->validated();

        $user = $login->attempt($validated['login'], $validated['password'], $request, $validated['captcha_token'] ?? null);

        return (new UserResource($user))->response();
    }

    public function destroy(Request $request, LoginService $login): Response
    {
        if ($request->hasSession()) {
            $login->logout($request);
        }

        return response()->noContent();
    }
}
