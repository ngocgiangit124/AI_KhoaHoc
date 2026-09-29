<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateContactRequest;
use App\Http\Resources\Auth\MeResource;
use App\Models\User;
use App\Services\Auth\ContactService;

/**
 * PUT /auth/contact (US-001, api-contract §2.2).
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contactService) {}

    public function update(UpdateContactRequest $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->contactService->update($user, $request->validated());

        return new MeResource($user);
    }
}
