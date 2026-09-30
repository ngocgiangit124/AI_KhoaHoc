<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Resources\Cart\CartResource;
use App\Models\Course;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /cart/items, DELETE /cart/items/{course} (US-004, api-contract §2.3).
 */
class CartItemController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function store(AddCartItemRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Đã qua rule exists (published, price > 0, chưa xoá) ở FormRequest.
        $course = Course::query()->findOrFail($request->integer('course_id'));

        return (new CartResource($this->cart->add($user, $course)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Route binding `withTrashed()`: khóa đã gỡ/xoá mềm vẫn phải xoá khỏi giỏ
     * được. Chỉ xoá dòng trong giỏ CỦA CHÍNH người dùng.
     */
    public function destroy(Request $request, Course $course): CartResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CartResource($this->cart->remove($user, $course->id));
    }
}
