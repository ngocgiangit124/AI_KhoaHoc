<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // `cart_count` (T16): số dòng trong giỏ cho badge icon giỏ hàng.
        return response()->json(array_merge(
            (new UserResource($user))->resolve($request),
            ['cart_count' => $this->cart->count($user)],
        ));
    }
}
