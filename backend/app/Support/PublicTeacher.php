<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/**
 * CHỐT DUY NHẤT quyết định API công khai có được trả ảnh/bio/headline của giáo viên hay không (US-020 BR5, ADR-005).
 *
 * Mọi Resource công khai về giáo viên (`GET /courses/{slug}`, `GET /home/teachers`, các API sau này) chỉ được xuất các
 * trường này qua class này. Điều kiện: `role = giao_vien` VÀ `teacher_profiles.public_consent_at` khác null. Không thoả thì
 * `bio`, `avatar_url` (và `headline`) là `null`, key vẫn có mặt (tương thích hợp đồng v1); `id`, `name` vẫn trả.
 * Test kiến trúc cấm Resource công khai đọc thẳng `avatar_path`/`bio`/`headline`.
 *
 * Người gọi phải eager load `teacherProfile` (tối thiểu cột `user_id, headline, bio, avatar_path, public_consent_at`) và
 * có sẵn `users.role`.
 */
final class PublicTeacher
{
    public static function consents(User $teacher): bool
    {
        return $teacher->role === UserRole::Teacher
            && $teacher->teacherProfile?->public_consent_at !== null;
    }

    /**
     * @return array{id: int, name: string, headline?: string|null, bio: string|null, avatar_url: string|null}
     */
    public static function toArray(User $teacher, bool $withHeadline = false): array
    {
        $profile = self::consents($teacher) ? $teacher->teacherProfile : null;

        $data = ['id' => $teacher->id, 'name' => $teacher->name];

        if ($withHeadline) {
            $data['headline'] = $profile?->headline;
        }

        return $data + [
            'bio' => $profile?->bio,
            'avatar_url' => StaticUrl::to($profile?->avatar_path),
        ];
    }
}
