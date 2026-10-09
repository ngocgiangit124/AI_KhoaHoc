<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\OrderFilterRequest;
use App\Http\Resources\Admin\AdminOrderDetailResource;
use App\Http\Resources\Admin\AdminOrderListResource;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\AdminOrderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Đơn hàng quản trị, phần ĐỌC (T24-V1, api-contract §2.5.1). Quyền `OrderPolicy@viewAny` (admin, quản lý trang): giáo viên 403
 * TRƯỚC validate và TRƯỚC khi tìm đơn (nên `{code}` là chuỗi, không bind model → mã lạ cũng không phân biệt được với 403 qua 404).
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly AdminOrderQuery $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function index(OrderFilterRequest $request): JsonResponse
    {
        // S1: tìm theo email/SĐT có thể tra ngược ra họ tên → giới hạn riêng (kiểm TRƯỚC khi truy vấn) và audit `order.search_contact`.
        $kind = $request->filled('q') ? AdminOrderQuery::searchKind((string) $request->input('q')) : null;
        $contact = in_array($kind, ['email', 'phone'], true);

        if ($contact) {
            $this->throttleContactSearch($request);
        }

        $page = $this->orders->paginate($request);

        if ($contact) {
            // KHÔNG lưu giá trị gốc: chỉ loại tìm kiếm, có kết quả hay không và HMAC (đối chiếu khi điều tra). Tên khoá trung tính vì
            // AuditLogger lọc khoá chứa email/phone và khoá kết thúc bằng _key/_code.
            $this->audit->log('order.search_contact', null, [
                'kind' => $kind,
                'hit' => $page['total'] > 0,
                'q_hash' => hash_hmac('sha256', AdminOrderQuery::normalizedContact((string) $request->input('q'), (string) $kind), (string) config('app.key')),
            ]);
        }

        return response()->json([
            'data' => AdminOrderListResource::collection($page['data'])->resolve($request),
            'meta' => [
                'per_page' => $page['per_page'],
                'next_cursor' => $page['next_cursor'],
                'prev_cursor' => $page['prev_cursor'],
                'total' => $page['total'],
            ],
        ]);
    }

    /** @throws TooManyRequestsHttpException */
    private function throttleContactSearch(OrderFilterRequest $request): void
    {
        $limits = (array) RateLimiter::limiter('admin-order-contact-search')($request);

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw new TooManyRequestsHttpException(RateLimiter::availableIn($limit->key), 'Bạn tìm theo email/SĐT quá nhiều lần, vui lòng thử lại sau.');
            }
        }

        foreach ($limits as $limit) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }
    }

    public function pendingCount(): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        return response()->json($this->orders->pendingCount());
    }

    /** Chi tiết: email/SĐT đầy đủ → đúng 1 audit `order.view_pii` mỗi lần gọi (FE không poll). */
    public function show(string $code): AdminOrderDetailResource
    {
        Gate::authorize('viewAny', Order::class);

        $order = $this->orders->findDetail($code);
        $resource = $this->orders->detailResource($order);

        // Ghi audit TRƯỚC khi trả dữ liệu: lỗi ghi → 500, không có đường lộ PII mà không để lại dấu vết. `changes` chỉ liệt kê loại dữ liệu.
        $this->audit->log('order.view_pii', $order, ['fields' => ['email', 'phone', 'customer_note']]);

        return $resource;
    }
}
