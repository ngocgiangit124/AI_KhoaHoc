<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\CancelOrderRequest;
use App\Http\Resources\Admin\AdminOrderDetailResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderQuery;
use App\Services\Orders\ManualOrderService;

/** Quản trị viên huỷ đơn thủ công kèm lý do cho học sinh (US-022, api-contract §2.5.1). Response = chi tiết đơn, KHÔNG ghi `order.view_pii`. */
class OrderCancellationController extends Controller
{
    public function __construct(
        private readonly ManualOrderService $manual,
        private readonly AdminOrderQuery $orders,
    ) {}

    public function store(CancelOrderRequest $request, string $code): AdminOrderDetailResource
    {
        /** @var User $staff */
        $staff = $request->user();

        $order = Order::query()->where('code', $code)->firstOrFail();
        $this->manual->cancelByStaff($order, $staff, $request->reason(), $request->note());

        return $this->orders->detailResource($this->orders->findDetail($code));
    }
}
