<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\RefundOrderRequest;
use App\Http\Resources\Admin\AdminOrderDetailResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderQuery;
use App\Services\Orders\RefundService;

/** Hoàn tiền (US-010, api-contract §2.5/§2.5.1). Response = chi tiết đơn, KHÔNG ghi thêm `order.view_pii` (đã có `order.refund`). */
class OrderRefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly AdminOrderQuery $orders,
    ) {}

    public function store(RefundOrderRequest $request, string $code): AdminOrderDetailResource
    {
        /** @var User $staff */
        $staff = $request->user();

        $order = Order::query()->where('code', $code)->firstOrFail();
        $this->refunds->refund($order, $staff, $request->note());

        return $this->orders->detailResource($this->orders->findDetail($code));
    }
}
