<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cart\CartResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

/**
 * GET /cart (US-004, api-contract §2.3). Giỏ luôn là của người đang đăng nhập
 * (không có tham số chọn giỏ → không có IDOR).
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): CartResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CartResource($this->cart->view($user));
    }
}
