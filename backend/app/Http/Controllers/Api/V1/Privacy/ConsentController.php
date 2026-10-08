<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\AcceptPolicyRequest;
use App\Http\Resources\ConsentResource;
use App\Models\User;
use App\Services\Privacy\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Đồng ý của CHÍNH học sinh (api-contract §2.8.3, ADR-006). Không có endpoint rút đồng ý.
 */
class ConsentController extends Controller
{
    public function index(Request $request, ConsentService $consents): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->payload($user, $consents);
    }

    public function accept(AcceptPolicyRequest $request, ConsentService $consents): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $consents->acceptCurrent($user, (string) $request->validated('policy_version'), $request);

        return $this->payload($user, $consents);
    }

    private function payload(User $user, ConsentService $consents): JsonResponse
    {
        return response()->json([
            'data' => ConsentResource::collection($consents->listFor($user))->resolve(),
            'meta' => [
                'current_policy_version' => (string) config('privacy.policy_version'),
                'needs_acceptance' => $consents->needsAcceptance($user),
            ],
        ]);
    }
}
