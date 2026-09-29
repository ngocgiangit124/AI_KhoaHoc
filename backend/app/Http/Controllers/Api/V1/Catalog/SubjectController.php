<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

/**
 * GET /subjects (US-002 BR5, US-011, api-contract §2.1) — chỉ chuyên đề
 * `active` (bộ lọc luôn phản ánh danh sách hiển thị tại thời điểm truy cập —
 * AC9). Danh sách KHÔNG phân trang nhưng vẫn bọc `{ "data": [...] }`
 * (api-contract §1.4).
 */
class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $subjects = Subject::query()->active()->orderBy('name')->get();

        return response()->json([
            'data' => SubjectResource::collection($subjects),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
