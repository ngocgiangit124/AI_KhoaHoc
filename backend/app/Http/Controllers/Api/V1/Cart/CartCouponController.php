<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\ApplyCouponRequest;
use App\Http\Resources\Cart\CartResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

/**
 * PUT|DELETE /cart/coupon (US-004 BR7, AC7-AC10, api-contract §2.3).
 */
class CartCouponController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function update(ApplyCouponRequest $request): CartResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CartResource(
            $this->cart->applyCoupon($user, (string) $request->validated('code'), $request->ip())
        );
    }

    public function destroy(Request $request): CartResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CartResource($this->cart->removeCoupon($user));
    }
}
