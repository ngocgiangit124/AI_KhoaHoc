<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CouponState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Coupon\CouponIndexRequest;
use App\Http\Requests\Admin\Coupon\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Coupons\CouponService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(CouponIndexRequest $request): AnonymousResourceCollection
    {
        $query = Coupon::query()
            ->withCount(['courses', 'subjects'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->inState(CouponState::from($request->string('state')->toString()));
        }

        if ($request->filled('q')) {
            $like = Like::contains(mb_strtoupper($request->string('q')->toString()));
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like));
        }

        return CouponResource::collection($query->paginate((int) $request->input('per_page', 25))->withQueryString());
    }

    public function show(Coupon $coupon): CouponResource
    {
        Gate::authorize('view', $coupon);

        return new CouponResource($coupon->load(['courses:id,title', 'subjects:id,name']));
    }

    public function store(CouponRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $coupon = $this->coupons->create($user, $request->payload());

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        return new CouponResource($this->coupons->update($coupon, $request->payload()));
    }

    public function activate(Coupon $coupon): CouponResource
    {
        Gate::authorize('update', $coupon);

        return new CouponResource($this->coupons->activate($coupon)->load(['courses:id,title', 'subjects:id,name']));
    }

    public function deactivate(Coupon $coupon): CouponResource
    {
        Gate::authorize('update', $coupon);

        return new CouponResource($this->coupons->deactivate($coupon)->load(['courses:id,title', 'subjects:id,name']));
    }

    public function destroy(Coupon $coupon): Response
    {
        Gate::authorize('delete', $coupon);

        $this->coupons->delete($coupon);

        return response()->noContent();
    }
}
