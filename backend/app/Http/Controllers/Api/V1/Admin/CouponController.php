<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Coupon\CouponService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * api-contract §2.5 (Mã giảm giá & đơn hàng, US-013). Route đăng ký ở
 * routes/admin.php, nhóm middleware `staff` (host admin-api).
 */
class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Coupon::class);

        $request->validate([
            // US-013 AC6 — filter theo trạng thái hiển thị (`Coupon::scopeState()`).
            'state' => ['nullable', Rule::in(['active', 'inactive', 'expired', 'exhausted'])],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = Coupon::query()->with(['courses', 'subjects'])->latest('id');

        if (is_string($state = $request->query('state'))) {
            $query->state($state);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 50));

        return CouponResource::collection($query->paginate($perPage));
    }

    /**
     * Phân quyền chạy trước ở middleware `can:create,...` của route.
     */
    public function store(CouponRequest $request): CouponResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $coupon = $this->coupons->create($request->validated(), $actor);

        return CouponResource::make($coupon);
    }

    /**
     * Phân quyền chạy trước ở middleware `can:update,coupon` của route.
     */
    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon = $this->coupons->update($coupon, $request->validated());

        return CouponResource::make($coupon);
    }

    /**
     * Phân quyền chạy trước ở middleware `can:deactivate,coupon` của route.
     */
    public function deactivate(Coupon $coupon): CouponResource
    {
        $coupon = $this->coupons->deactivate($coupon);

        return CouponResource::make($coupon->load('courses', 'subjects'));
    }

    /**
     * Phân quyền chạy trước ở middleware `can:delete,coupon` của route.
     */
    public function destroy(Coupon $coupon): Response
    {
        $this->coupons->delete($coupon);

        return response()->noContent();
    }
}
