<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ô chọn giáo viên khi gán khóa học: chỉ staff, chỉ `id` + `name` (không email/SĐT). */
class TeacherController extends Controller
{
    private const LIMIT = 500;

    public function index(Request $request): JsonResponse
    {
        // Danh sách giáo viên để gán khóa chỉ dành cho staff (giáo viên → 403 FORBIDDEN).
        if ($request->user()?->isStaff() !== true) {
            throw new AuthorizationException;
        }

        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $query = User::query()
            ->where('role', UserRole::Teacher)->where('status', UserStatus::Active)
            ->orderBy('name')->orderBy('id')->limit(self::LIMIT);

        if ($request->filled('q')) {
            $query->where('name', 'like', Like::contains($request->string('q')->toString()));
        }

        return response()->json([
            'data' => $query->get(['id', 'name'])->map(fn (User $t) => ['id' => $t->id, 'name' => $t->name])->all(),
        ]);
    }
}
