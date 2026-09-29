<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\StudentSessionService;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/register (host api, `guest, throttle:register` — api-contract
 * §2.2). Controller mỏng: validate (Form Request) → Service (nghiệp vụ) →
 * đăng nhập + bind phiên đầy đủ 1 thiết bị/1 phiên (T05/ADR-003).
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly StudentSessionService $studentSessionService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->register(
            $request->validated(),
            $request->ip() ?? '0.0.0.0',
            $request->userAgent(),
        );

        // T05 (ADR-003) — tài khoản vừa tạo chắc chắn chưa có phiên nào khác,
        // nhưng vẫn qua `bind()` để ghi current_session_id/current_device_id
        // theo đúng 1 nơi duy nhất được phép ghi (quy tắc cho Dev, ADR-003).
        $this->studentSessionService->bind($request, $user);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
