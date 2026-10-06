<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateContactRequest;
use App\Models\User;
use App\Services\Auth\ContactService;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function update(UpdateContactRequest $request, ContactService $contact): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resendAt = $contact->update($user, $request->validated(), $request);

        return response()->json(['resend_available_at' => $resendAt?->toIso8601String()]);
    }
}
