<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TeacherResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * `GET /admin/teachers` (US-009, api-contract §2.5). Danh sách phẳng để điền
 * `<MultiSelect>` "Giáo viên phụ trách" — chỉ `id`/`name` (không email/SĐT).
 * Staff-only qua `role:admin,quan_ly_trang` bổ sung trên route (chỉ Admin/
 * Quản lý trang được GÁN giáo viên phụ trách — BR9; Giáo Viên không cần danh
 * sách này vì không có quyền `manageTeachers`, xem `CoursePolicy`).
 */
class TeacherController extends Controller
{
    public function index(): JsonResponse
    {
        $teachers = User::query()
            ->where('role', UserRole::Teacher)
            ->orderBy('name')
            ->get(['id', 'name']);

        // api-contract §1.4: danh sách luôn bọc `data` (kể cả không phân trang);
        // `withoutWrapping()` toàn cục nên bọc thủ công.
        return response()->json(['data' => TeacherResource::collection($teachers)->resolve()]);
    }
}
