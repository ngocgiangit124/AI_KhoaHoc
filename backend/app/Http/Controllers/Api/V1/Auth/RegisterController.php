<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegistrationService $registration): JsonResponse
    {
        $this->ensureSession($request);

        $user = $registration->register($request->validated(), $request);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    private function ensureSession(RegisterRequest $request): void
    {
        if (! $request->hasSession()) {
            throw new DomainException('ORIGIN_NOT_ALLOWED', 'Nguồn gọi không được phép.', 400);
        }
    }
}
