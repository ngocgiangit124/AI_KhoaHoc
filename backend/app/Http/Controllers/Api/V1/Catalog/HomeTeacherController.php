<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\HomeTeacherResource;
use App\Services\Teachers\HomepageTeacherQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Khu vực giáo viên ở trang chủ (US-020). Công khai, không tham số, ≤ `homepage_max` người. Laravel không cache
 * (header `Cache-Control: public, max-age=60` + ETag do middleware `cache.headers` của nhóm catalog).
 */
class HomeTeacherController extends Controller
{
    public function __construct(private readonly HomepageTeacherQuery $teachers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => HomeTeacherResource::collection($this->teachers->get())->resolve($request),
        ]);
    }
}
