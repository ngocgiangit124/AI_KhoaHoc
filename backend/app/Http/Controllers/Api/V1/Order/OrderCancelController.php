<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyOrderDetailResource;
use App\Models\User;
use App\Services\Orders\ManualOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /orders/{code}/cancel — học sinh tự huỷ đơn `manual` `pending` của mình (US-022 BR14). */
class OrderCancelController extends Controller
{
    public function __construct(private readonly ManualOrderService $orders) {}

    public function store(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = MyOrderController::findOwned($user, $code);
        Gate::authorize('cancel', $order);

        $this->orders->cancelByStudent($order, $user);

        return (new MyOrderDetailResource(MyOrderController::findOwned($user, $code)))->response();
    }
}
