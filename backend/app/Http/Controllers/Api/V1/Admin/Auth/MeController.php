<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Auth\StaffMeResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * GET /admin/auth/me (host admin-api, nhóm `staff` — api-contract §2.5).
 */
class MeController extends Controller
{
    public function __invoke(Request $request): StaffMeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new StaffMeResource($user);
    }
}
