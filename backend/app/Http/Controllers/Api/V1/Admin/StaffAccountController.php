<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Staff\StaffIndexRequest;
use App\Http\Requests\Admin\Staff\StaffRoleRequest;
use App\Http\Requests\Admin\Staff\StaffStoreRequest;
use App\Http\Resources\StaffAccountResource;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Quản lý tài khoản staff (US-016, T33): chỉ admin (Gate `manage-system`). Học sinh không bao giờ xuất hiện
 * ở đây (tìm theo id học sinh → 404).
 */
class StaffAccountController extends Controller
{
    public function __construct(private readonly StaffAccountService $accounts) {}

    public function index(StaffIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->staffQuery()->orderByDesc('created_at')->orderByDesc('id');

        if (($role = $request->roleFilter()) !== null) {
            $query->where('role', $role->value);
        }

        if ($request->filled('status')) {
            $query->where('status', UserStatus::from($request->string('status')->toString())->value);
        }

        if ($request->filled('q')) {
            $like = Like::contains($request->string('q')->toString());
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        return StaffAccountResource::collection($query->paginate((int) $request->input('per_page', 25))->withQueryString());
    }

    public function show(string $staff): StaffAccountResource
    {
        Gate::authorize('manage-system');

        return new StaffAccountResource($this->find($staff));
    }

    public function store(StaffStoreRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $this->accounts->create(
            $actor,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            UserRole::from($request->string('role')->toString()),
        );

        return $this->withPassword($result['user'], $result['password'])->setStatusCode(201);
    }

    public function lock(Request $request, string $staff): StaffAccountResource
    {
        Gate::authorize('manage-system');

        return new StaffAccountResource($this->accounts->lock($this->find($staff), $this->actor($request)));
    }

    public function unlock(string $staff): StaffAccountResource
    {
        Gate::authorize('manage-system');

        return new StaffAccountResource($this->accounts->unlock($this->find($staff)));
    }

    public function updateRole(StaffRoleRequest $request, string $staff): JsonResponse
    {
        ['user' => $user, 'released_course_ids' => $released, 'released_courses' => $releasedCourses] = $this->accounts->changeRoleDetailed(
            $this->find($staff),
            UserRole::from($request->string('role')->toString()),
            $this->actor($request),
        );

        return response()->json([
            ...(new StaffAccountResource($user))->resolve(),
            'released_course_ids' => $released,
            'released_courses' => $releasedCourses,
        ]);
    }

    public function resetPassword(Request $request, string $staff): JsonResponse
    {
        Gate::authorize('manage-system');

        $result = $this->accounts->resetPassword($this->find($staff), $this->actor($request));

        return $this->withPassword($result['user'], $result['password']);
    }

    private function withPassword(User $user, string $password): JsonResponse
    {
        // Phản hồi phẳng như mọi resource đơn (withoutWrapping): tài khoản + mật khẩu hiển thị MỘT lần.
        return response()->json([
            ...(new StaffAccountResource($user))->resolve(),
            'initial_password' => $password,
        ]);
    }

    /** @return Builder<User> */
    private function staffQuery(): Builder
    {
        return User::query()->whereIn('role', array_map(fn (UserRole $r) => $r->value, StaffAccountService::ROLES));
    }

    private function find(string $id): User
    {
        abort_unless(ctype_digit($id), 404);

        return $this->staffQuery()->findOrFail((int) $id);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
