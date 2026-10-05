<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CourseSearchRequest;
use App\Http\Resources\Catalog\CourseDetailResource;
use App\Http\Resources\Catalog\CourseListResource;
use App\Models\User;
use App\Services\Catalog\CourseCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    public function index(CourseSearchRequest $request): JsonResponse
    {
        $paginator = $this->catalog->search(
            $request->filled('grade') ? $request->integer('grade') : null,
            array_map('intval', (array) $request->input('subject_ids', [])),
            $request->filled('q') ? $request->string('q')->toString() : null,
            $request->input('sort', 'newest'),
            $request->integer('page', 1),
        );

        return response()->json([
            'data' => CourseListResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'next' => $paginator->hasMorePages() ? $this->pageLink($request, $paginator->currentPage() + 1) : null,
                'prev' => $paginator->currentPage() > 1 ? $this->pageLink($request, $paginator->currentPage() - 1) : null,
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $course = $this->catalog->findPublished($slug)
            ?? throw new DomainException('NOT_FOUND', 'Không tìm thấy khóa học.', 404);

        return (new CourseDetailResource($course))->response($request);
    }

    public function viewerState(Request $request, string $slug): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $state = $this->catalog->viewerState($slug, $user)
            ?? throw new DomainException('NOT_FOUND', 'Không tìm thấy khóa học.', 404);

        return response()->json($state);
    }

    /**
     * Link tương đối, không phụ thuộc Host/scheme do client gửi (response được cache công khai).
     * Chỉ giữ tham số đã validate.
     */
    private function pageLink(CourseSearchRequest $request, int $page): string
    {
        $query = array_filter(
            $request->only(['grade', 'subject_ids', 'q', 'sort']),
            fn ($v) => $v !== null && $v !== '',
        );
        $query['page'] = $page;

        return '/api/v1/courses?'.http_build_query($query);
    }
}
