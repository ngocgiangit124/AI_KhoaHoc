<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\ApproveOrderRequest;
use App\Http\Resources\Admin\AdminOrderDetailResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderQuery;
use App\Services\Orders\ManualOrderService;

/** Duyệt / duyệt muộn đơn thủ công (US-022, api-contract §2.5.1). Response = chi tiết đơn, KHÔNG ghi `order.view_pii` (đã có `order.manual_approve`). */
class OrderApprovalController extends Controller
{
    public function __construct(
        private readonly ManualOrderService $manual,
        private readonly AdminOrderQuery $orders,
    ) {}

    public function store(ApproveOrderRequest $request, string $code): AdminOrderDetailResource
    {
        /** @var User $staff */
        $staff = $request->user();

        $order = Order::query()->where('code', $code)->firstOrFail();
        $this->manual->approve($order, $staff, $request->isLate(), $request->paymentReference(), $request->note());

        return $this->orders->detailResource($this->orders->findDetail($code));
    }
}
