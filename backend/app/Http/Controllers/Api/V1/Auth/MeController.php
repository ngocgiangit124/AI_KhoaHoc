<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\MeResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * GET /auth/me (host api, nhóm `student` — api-contract §2.2).
 */
class MeController extends Controller
{
    public function __invoke(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeResource($user);
    }
}
