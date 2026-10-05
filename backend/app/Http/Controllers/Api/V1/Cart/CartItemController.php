<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartItemController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function store(AddCartItemRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CartResource($this->cart->addItem($user, (int) $request->integer('course_id'))))->response()->setStatusCode(201);
    }

    /** `{course}` là id số (không bind model: khóa đã xoá mềm vẫn phải gỡ được khỏi giỏ). */
    public function destroy(Request $request, int $course): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CartResource($this->cart->removeItem($user, $course)))->response();
    }
}
