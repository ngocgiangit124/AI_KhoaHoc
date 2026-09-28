<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * POST /auth/register (host api, `guest, throttle:register` — api-contract
 * §2.2). Controller mỏng: validate (Form Request) → Service (nghiệp vụ) →
 * đăng nhập ngay (bind phiên đầy đủ 1 thiết bị/1 phiên là T05/ADR-003).
 */
class RegisterController extends Controller
{
    public function __construct(private readonly RegistrationService $registrationService) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->register(
            $request->validated(),
            $request->ip() ?? '0.0.0.0',
            $request->userAgent(),
        );

        Auth::login($user);
        $request->session()->regenerate();

        // TODO(T05): StudentSessionService::bind() — ghi current_session_id/
        // current_device_id và huỷ phiên khác (ADR-003). Ở T03, đăng ký chỉ
        // đăng nhập đơn giản, chưa ép "1 thiết bị/1 phiên".

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
