<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\StudentSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /auth/login`, `POST /auth/logout` (host api — api-contract §2.2).
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly StudentSessionService $studentSessionService,
    ) {}

    public function store(LoginRequest $request): UserResource
    {
        $user = $this->loginService->authenticate(
            (string) $request->validated('login'),
            (string) $request->validated('password'),
        );

        // T05 (ADR-003) — huỷ phiên cũ (nếu có) + tombstone + ghi
        // current_session_id/current_device_id, tất cả trong `bind()`.
        $this->studentSessionService->bind($request, $user);

        return new UserResource($user);
    }

    /**
     * Ngoại lệ duy nhất của nhóm `student` (api-contract §1.3): chỉ cần
     * `auth:sanctum`, không cần `account.active`/`no_store`/`role:hoc_sinh`
     * (đăng xuất phải luôn thực hiện được, kể cả tài khoản vừa bị khoá).
     */
    public function destroy(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // T05 (ADR-003, AC3) — đặt `logged_out` TRƯỚC khi invalidate session
        // cục bộ, có điều kiện (chỉ khi vẫn là phiên hiện hành) để không ảnh
        // hưởng phiên của thiết bị khác vừa đăng nhập đúng lúc này.
        $this->studentSessionService->logout($user, $request->session()->getId());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
