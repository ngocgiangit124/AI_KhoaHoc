<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherProfile\TeacherAvatarRequest;
use App\Http\Requests\TeacherProfile\TeacherProfileIndexRequest;
use App\Http\Requests\TeacherProfile\UpdateTeacherHomepageRequest;
use App\Http\Requests\TeacherProfile\UpdateTeacherProfileRequest;
use App\Http\Resources\Admin\TeacherProfileListItemResource;
use App\Http\Resources\Admin\TeacherProfileResource;
use App\Models\User;
use App\Services\Teachers\TeacherProfileReader;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Quản lý hồ sơ giáo viên (US-020): Admin/QLT (Gate `manage-teacher-profiles`, kiểm TRƯỚC khi tìm bản ghi để giáo viên
 * nhận 403 chứ không dò được id nào tồn tại). Sửa hộ nội dung, bật/tắt trang chủ, đặt thứ tự. KHÔNG có đường đồng ý thay.
 * `{user}` là id số của giáo viên hoặc của người đã đổi vai trò nhưng còn dòng hồ sơ; còn lại 404.
 */
class TeacherProfileController extends Controller
{
    public function __construct(
        private readonly TeacherProfileService $profiles,
        private readonly TeacherProfileReader $reader,
    ) {}

    public function index(TeacherProfileIndexRequest $request): AnonymousResourceCollection
    {
        $paginator = $this->reader->paginate(
            $request->filled('q') ? $request->string('q')->toString() : null,
            $request->input('homepage') !== null && (string) $request->input('homepage') === '1',
            (int) $request->input('per_page', 25),
            $request->integer('page', 1),
        );

        return TeacherProfileListItemResource::collection($paginator->withQueryString())->additional([
            'meta' => ['homepage' => [
                'enabled_count' => $this->reader->enabledCount(),
                'max' => (int) config('teacher_profile.homepage_max'),
            ]],
        ]);
    }

    public function show(string $user): TeacherProfileResource
    {
        Gate::authorize('manage-teacher-profiles');

        return new TeacherProfileResource($this->find($user));
    }

    public function update(UpdateTeacherProfileRequest $request, string $user): TeacherProfileResource
    {
        $target = $this->find($user);
        $this->profiles->updateContent($target, $request->validated(), $this->actor($request));

        return $this->respond($target);
    }

    public function storeAvatar(TeacherAvatarRequest $request, string $user): TeacherProfileResource
    {
        $target = $this->find($user);
        /** @var UploadedFile $file */
        $file = $request->file('avatar');
        $this->profiles->replaceAvatar($target, $file, $this->actor($request));

        return $this->respond($target);
    }

    public function destroyAvatar(Request $request, string $user): TeacherProfileResource
    {
        Gate::authorize('manage-teacher-profiles');

        $target = $this->find($user);
        $this->profiles->removeAvatar($target, $this->actor($request));

        return $this->respond($target);
    }

    public function updateHomepage(UpdateTeacherHomepageRequest $request, string $user): TeacherProfileResource
    {
        $target = $this->find($user);

        $this->profiles->setHomepage(
            $target,
            $request->has('show_on_homepage') ? $request->boolean('show_on_homepage') : null,
            $request->has('homepage_order'),
            $request->filled('homepage_order') ? $request->integer('homepage_order') : null,
        );

        return $this->respond($target);
    }

    private function find(string $id): User
    {
        abort_unless(ctype_digit($id) && strlen($id) <= 18, 404);

        return $this->reader->findManaged((int) $id) ?? abort(404);
    }

    private function respond(User $user): TeacherProfileResource
    {
        return new TeacherProfileResource($this->reader->load((int) $user->getKey()));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
