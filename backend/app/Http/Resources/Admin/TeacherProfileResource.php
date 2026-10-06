<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Services\Teachers\TeacherEligibility;
use App\Support\StaticUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hồ sơ giáo viên cho màn quản trị (api-contract §2.9 `TeacherProfile`), dùng chung cho `/admin/me/teacher-profile` và
 * `/admin/teacher-profiles/{user}`. KHÔNG phải API công khai nên đọc thẳng hồ sơ (người xem là chính giáo viên hoặc staff).
 * Cần `User` có quan hệ `teacherProfile.updatedBy` và thuộc tính `published_courses_count` (xem `TeacherProfileReader`).
 * Không email/SĐT.
 *
 * @mixin User
 */
class TeacherProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $profile = $user->teacherProfile;
        $publishedCourses = (int) $user->getAttribute('published_courses_count');
        $reasons = TeacherEligibility::reasons($user, $profile, $publishedCourses);
        $editor = $profile?->updatedBy;

        $given = false;
        $givenAt = null;
        $version = null;
        $withdrawnAt = $profile?->public_consent_withdrawn_at?->toIso8601String();

        if ($profile !== null && $profile->public_consent_at !== null) {
            $given = true;
            $givenAt = $profile->public_consent_at->toIso8601String();
            $version = $profile->public_consent_version;
            $withdrawnAt = null; // đang đồng ý: lần rút cũ không còn ý nghĩa với màn hình
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
                'status' => $user->status->value,
            ],
            'headline' => $profile?->headline,
            'bio' => $profile?->bio,
            'avatar_url' => StaticUrl::to($profile?->avatar_path),
            'consent' => [
                'given' => $given,
                'given_at' => $givenAt,
                'version' => $version,
                'withdrawn_at' => $withdrawnAt,
                'current_version' => (string) config('teacher_profile.consent_version'),
                'current_text' => (string) config('teacher_profile.consent_text'),
            ],
            'show_on_homepage' => (bool) $profile?->show_on_homepage,
            'homepage_order' => $profile?->homepage_order,
            'homepage_status' => ['visible' => $reasons === [], 'reasons' => $reasons],
            'published_courses_count' => $publishedCourses,
            'last_edited_by' => $editor === null ? null : [
                'id' => $editor->id,
                'name' => $editor->name,
                'is_self' => $request->user()?->getKey() === $editor->getKey(),
            ],
            'last_edited_at' => $profile?->profile_updated_at?->toIso8601String(),
            'updated_at' => $profile?->updated_at?->toIso8601String(),
            'abilities' => $this->abilities($request, $user),
        ];
    }

    /**
     * Chỉ để ẩn/hiện UI (quyền thật kiểm ở Gate): giáo viên ở `/me` tự đồng ý được; Admin/QLT không đồng ý thay.
     *
     * @return array<string, bool>
     */
    protected function abilities(Request $request, User $user): array
    {
        // Chính chủ ở `/me`: giáo viên được sửa/đồng ý; người đã đổi vai trò chỉ còn gỡ (rút đồng ý, xoá ảnh).
        if ($request->user()?->getKey() === $user->getKey()) {
            return ['edit_content' => $user->isTeacher(), 'consent' => $user->isTeacher(), 'manage_homepage' => false];
        }

        return ['edit_content' => $user->isTeacher(), 'consent' => false, 'manage_homepage' => true];
    }
}
