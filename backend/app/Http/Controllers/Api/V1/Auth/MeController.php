<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParentContactResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Privacy\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly ConsentService $consents,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // `cart_count` (T16): số dòng trong giỏ cho badge icon giỏ hàng.
        return response()->json(array_merge(
            (new UserResource($user))->resolve($request),
            [
                'cart_count' => $this->cart->count($user),
                // T29/T34 (ADR-006): liên hệ phụ huynh LUÔN đã che; cờ để FE hiện banner chấp nhận lại chính sách.
                'parent_contact' => (new ParentContactResource($user))->resolve($request),
                'needs_policy_acceptance' => $this->consents->needsPolicyAcceptance($user),
            ],
        ));
    }
}
