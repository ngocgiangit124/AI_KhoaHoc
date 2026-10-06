<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\ApplyCouponRequest;
use App\Http\Resources\CartResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartCouponController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function update(ApplyCouponRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CartResource($this->cart->applyCoupon($user, $request->string('code')->toString(), $request->ip())))->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CartResource($this->cart->removeCoupon($user)))->response();
    }
}
