<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\StoreOrderNoteRequest;
use App\Http\Resources\Admin\OrderNoteResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ManualOrderService;
use Illuminate\Http\JsonResponse;

/** Ghi chú nội bộ trên đơn (US-022 BR18). Không có route sửa/xoá. */
class OrderNoteController extends Controller
{
    public function __construct(private readonly ManualOrderService $manual) {}

    public function store(StoreOrderNoteRequest $request, string $code): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();

        $order = Order::query()->where('code', $code)->firstOrFail();
        $note = $this->manual->addNote($order, $staff, $request->body());
        $note->setRelation('author', $staff);

        return (new OrderNoteResource($note))->response()->setStatusCode(201);
    }
}
