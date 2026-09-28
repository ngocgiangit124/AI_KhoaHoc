<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\LoginService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /auth/login`, `POST /auth/logout` (host api — api-contract §2.2).
 */
class LoginController extends Controller
{
    public function __construct(private readonly LoginService $loginService) {}

    public function store(LoginRequest $request): UserResource
    {
        $user = $this->loginService->authenticate(
            (string) $request->validated('login'),
            (string) $request->validated('password'),
        );

        Auth::login($user);
        $request->session()->regenerate();

        // TODO(T05): StudentSessionService::bind() (ADR-003) — huỷ phiên cũ +
        // tombstone. Ở T03, đăng nhập chưa ép "1 thiết bị/1 phiên".

        return new UserResource($user);
    }

    /**
     * Ngoại lệ duy nhất của nhóm `student` (api-contract §1.3): chỉ cần
     * `auth:sanctum`, không cần `account.active`/`no_store`/`role:hoc_sinh`
     * (đăng xuất phải luôn thực hiện được, kể cả tài khoản vừa bị khoá).
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
