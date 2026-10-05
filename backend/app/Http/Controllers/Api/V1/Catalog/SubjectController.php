<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\SubjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\PublicSubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

/**
 * GET /subjects (public) — chỉ chuyên đề `active` (US-002 BR5/AC9). Không phân trang.
 */
class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $subjects = Subject::query()
            ->where('status', SubjectStatus::Active->value)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'slug']);

        return response()->json(['data' => PublicSubjectResource::collection($subjects)->resolve(request())]);
    }
}
