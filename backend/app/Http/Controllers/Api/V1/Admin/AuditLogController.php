<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Staff\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Nhật ký thao tác — CHỈ ĐỌC (US-016 BR8/AC9), chỉ admin. Dùng simplePaginate (không đếm tổng) vì bảng lớn.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id');

        foreach (['action', 'actor_id', 'subject_type', 'subject_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from', 'Y-m-d')?->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to', 'Y-m-d')?->endOfDay());
        }

        return AuditLogResource::collection($query->simplePaginate((int) $request->input('per_page', 25))->withQueryString());
    }
}
