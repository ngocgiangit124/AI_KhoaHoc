<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subject\SubjectIndexRequest;
use App\Http\Requests\Admin\Subject\SubjectRequest;
use App\Http\Requests\Admin\Subject\SubjectStatusRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Models\User;
use App\Services\Subjects\SubjectService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SubjectController extends Controller
{
    public function __construct(private readonly SubjectService $subjects) {}

    public function index(SubjectIndexRequest $request): AnonymousResourceCollection|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Subject::query()->orderBy('name')->orderBy('id');

        if ($user->isStaff()) {
            $query->withCount(['courses' => fn ($q) => $q->withTrashed()]);

            if ($request->filled('status')) {
                $query->where('status', $request->string('status')->toString());
            }
        } else {
            // Giáo viên chỉ thấy chuyên đề `active` (US-011 BR3/BR4), bỏ qua bộ lọc status.
            $query->where('status', SubjectStatus::Active);
        }

        if ($request->filled('q')) {
            $query->where('name', 'like', Like::contains($request->string('q')->toString()));
        }

        if ($request->boolean('all')) {
            // withoutWrapping() không bọc collection → tự bọc `data` để đúng hình dạng danh sách (§1.4).
            return response()->json(['data' => SubjectResource::collection($query->get())->resolve($request)]);
        }

        return SubjectResource::collection($query->paginate((int) $request->input('per_page', 25))->withQueryString());
    }

    public function store(SubjectRequest $request): JsonResponse
    {
        Gate::authorize('create', Subject::class);

        $subject = $this->subjects->create($request->validated('name'));

        return (new SubjectResource($subject))->response()->setStatusCode(201);
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        Gate::authorize('update', $subject);

        return new SubjectResource($this->subjects->rename($subject, $request->validated('name')));
    }

    public function updateStatus(SubjectStatusRequest $request, Subject $subject): SubjectResource
    {
        Gate::authorize('update', $subject);

        return new SubjectResource($this->subjects->setStatus($subject, SubjectStatus::from($request->validated('status'))));
    }

    public function destroy(Subject $subject): Response
    {
        Gate::authorize('delete', $subject);

        $this->subjects->delete($subject);

        return response()->noContent();
    }
}
