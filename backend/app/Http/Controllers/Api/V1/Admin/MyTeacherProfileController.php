<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherProfile\TeacherAvatarRequest;
use App\Http\Requests\TeacherProfile\TeacherConsentRequest;
use App\Http\Requests\TeacherProfile\UpdateTeacherProfileRequest;
use App\Http\Resources\Admin\TeacherProfileResource;
use App\Models\User;
use App\Services\Teachers\TeacherProfileReader;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * "Hồ sơ của tôi" (US-020): CHỈ giáo viên, chỉ hồ sơ của chính mình (không có `{user}` trên route nên không có IDOR).
 * Admin/QLT gọi các route này → 403 (Gate `own-teacher-profile`, kiểm trước validate). Đồng ý/rút đồng ý chỉ có ở đây.
 * Ngoại lệ (L1): rút đồng ý và xoá ảnh dùng Gate `withdraw-own-teacher-profile` để người đã đổi vai trò vẫn còn hồ sơ tự gỡ được.
 */
class MyTeacherProfileController extends Controller
{
    public function __construct(
        private readonly TeacherProfileService $profiles,
        private readonly TeacherProfileReader $reader,
    ) {}

    public function show(Request $request): TeacherProfileResource
    {
        Gate::authorize('own-teacher-profile');

        return $this->respond($this->actor($request));
    }

    public function update(UpdateTeacherProfileRequest $request): TeacherProfileResource
    {
        $actor = $this->actor($request);
        $this->profiles->updateContent($actor, $request->validated(), $actor);

        return $this->respond($actor);
    }

    public function storeAvatar(TeacherAvatarRequest $request): TeacherProfileResource
    {
        $actor = $this->actor($request);
        /** @var UploadedFile $file */
        $file = $request->file('avatar');
        $this->profiles->replaceAvatar($actor, $file, $actor);

        return $this->respond($actor);
    }

    public function destroyAvatar(Request $request): TeacherProfileResource
    {
        Gate::authorize('withdraw-own-teacher-profile');

        $actor = $this->actor($request);
        $this->profiles->removeAvatar($actor, $actor);

        return $this->respond($actor);
    }

    public function consent(TeacherConsentRequest $request): TeacherProfileResource
    {
        $actor = $this->actor($request);
        $this->profiles->giveConsent($actor, $request->string('version')->toString());

        return $this->respond($actor);
    }

    public function withdrawConsent(Request $request): TeacherProfileResource
    {
        Gate::authorize('withdraw-own-teacher-profile');

        $actor = $this->actor($request);
        $this->profiles->withdrawConsent($actor);

        return $this->respond($actor);
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
