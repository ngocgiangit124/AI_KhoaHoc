<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubjectRequest;
use App\Http\Requests\Admin\UpdateSubjectStatusRequest;
use App\Http\Resources\Admin\SubjectResource;
use App\Models\Subject;
use App\Services\Catalog\SubjectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * api-contract §2.5 (Nội dung & danh mục — Chuyên đề, US-011). Route đăng
 * ký ở routes/admin.php, nhóm middleware `staff` (host admin-api).
 */
class SubjectController extends Controller
{
    public function __construct(private readonly SubjectService $subjects) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subject::class);

        $query = Subject::query()->orderBy('name');

        // GV chỉ nhận `active` (api-contract §2.5).
        if (! $request->user()->isStaff()) {
            $query->where('status', SubjectStatus::Active);
        }

        $perPage = min((int) $request->integer('per_page', 25), 50);

        return SubjectResource::collection($query->paginate($perPage));
    }

    public function store(SubjectRequest $request): SubjectResource
    {
        $this->authorize('create', Subject::class);

        $subject = $this->subjects->create($request->validated());

        return SubjectResource::make($subject);
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        $this->authorize('update', $subject);

        $subject = $this->subjects->update($subject, $request->validated());

        return SubjectResource::make($subject);
    }

    public function destroy(Subject $subject): Response
    {
        $this->authorize('delete', $subject);

        $this->subjects->delete($subject);

        return response()->noContent();
    }

    public function updateStatus(UpdateSubjectStatusRequest $request, Subject $subject): SubjectResource
    {
        $this->authorize('updateStatus', $subject);

        $subject = $this->subjects->updateStatus($subject, SubjectStatus::from($request->validated('status')));

        return SubjectResource::make($subject);
    }
}
