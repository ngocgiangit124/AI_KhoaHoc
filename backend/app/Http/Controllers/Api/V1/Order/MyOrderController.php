<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\MyOrdersRequest;
use App\Http\Resources\MyOrderDetailResource;
use App\Http\Resources\MyOrderListResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Đơn của tôi (US-022, api-contract §2.3.1). Tra đơn theo `code` VÀ `user_id`: đơn của người khác hay mã không tồn tại cùng 404.
 */
class MyOrderController extends Controller
{
    private const PER_PAGE = 10;

    public function index(MyOrdersRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = Order::query()
            ->where('user_id', $user->getKey())
            ->withCount('items')
            ->with('items:id,order_id,course_title')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: $request->integer('page', 1));

        $this->attachReplacedByCodes($user, $page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => (new MyOrderListResource($o))->resolve($request))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    /**
     * `replaced_by_code` cho cả trang bằng MỘT truy vấn: với đơn `superseded`, mã đơn kế tiếp của HS (id nhỏ nhất lớn hơn).
     *
     * @param  Collection<int, Order>  $orders
     */
    private function attachReplacedByCodes(User $user, Collection $orders): void
    {
        $superseded = $orders->filter(fn (Order $o) => $o->status_reason === 'superseded');

        if ($superseded->isEmpty()) {
            return;
        }

        $following = Order::query()
            ->where('user_id', $user->getKey())
            ->where('id', '>', $superseded->min('id'))
            ->orderBy('id')
            ->get(['id', 'code']);

        foreach ($superseded as $order) {
            $order->setAttribute('replaced_by_code', $following->first(fn (Order $n) => $n->id > $order->id)?->code);
        }
    }

    public function show(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = self::findOwned($user, $code);
        Gate::authorize('view', $order);

        return (new MyOrderDetailResource($order))->response();
    }

    /** 404 (ModelNotFoundException → NOT_FOUND) cho mã không tồn tại HOẶC của người khác. */
    public static function findOwned(User $user, string $code): Order
    {
        return Order::query()
            ->where('code', $code)
            ->where('user_id', $user->getKey())
            ->with('items.course')
            ->firstOrFail();
    }
}
