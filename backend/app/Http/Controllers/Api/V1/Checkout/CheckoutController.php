<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutRequest;
use App\Http\Resources\CheckoutPreviewResource;
use App\Models\User;
use App\Services\Orders\CheckoutChangedException;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function preview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new CheckoutPreviewResource($this->checkout->preview($user)))->response();
    }

    /**
     * 201 đơn mới / 200 dùng lại đơn pending cũ. `payment` null khi đơn 0đ (đã `paid`) hoặc link hết hạn chờ đối soát.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->checkout->checkout($user, (int) $request->validated('expected_total'), $request->gateway());
        } catch (CheckoutChangedException $e) {
            throw new DomainException($e->code(), $e->getMessage(), $e->status(), [
                'reasons' => $e->reasons,
                'preview' => (new CheckoutPreviewResource($e->snapshot))->toArray($request),
            ]);
        }

        $order = $result->order;
        $attempt = $result->attempt;

        return response()->json([
            'order_code' => $order->code,
            'status' => $order->status->value,
            'total' => $order->total_amount,
            'reused' => $result->reused,
            'payment' => $attempt?->pay_url === null ? null : [
                'gateway' => $attempt->gateway,
                'pay_url' => $attempt->pay_url,
                'expires_at' => $attempt->expires_at?->toIso8601String(),
            ],
            'link_expired' => $result->linkExpired,
        ], $result->reused ? 200 : 201);
    }
}
