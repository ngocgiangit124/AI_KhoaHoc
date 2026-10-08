<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\UpdateParentContactRequest;
use App\Http\Resources\ParentContactResource;
use App\Models\User;
use App\Services\Privacy\ParentContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentContactController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ParentContactResource($user))->response();
    }

    public function update(UpdateParentContactRequest $request, ParentContactService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $service->update($user, $request->validated());

        return (new ParentContactResource($updated))->response();
    }
}
