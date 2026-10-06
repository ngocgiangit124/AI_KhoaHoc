<?php

namespace App\Services\Teachers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\VisibleText;

/**
 * Hàm THUẦN: vì sao một giáo viên chưa hiện ở trang chủ (BR2). Dùng cho màn quản trị ("Chưa hiện: {lý do}").
 * Phải khớp với SQL của `HomepageTeacherQuery` (test ma trận kiểm cả hai). `visible = reasons rỗng`.
 *
 * Thứ tự lý do cố định (api-contract §2.9).
 */
final class TeacherEligibility
{
    public const NOT_TEACHER = 'not_teacher';

    public const ACCOUNT_LOCKED = 'account_locked';

    public const NOT_ENABLED = 'not_enabled';

    public const NO_CONSENT = 'no_consent';

    public const NO_AVATAR = 'no_avatar';

    public const NO_BIO = 'no_bio';

    public const NO_PUBLISHED_COURSE = 'no_published_course';

    /**
     * @return list<string>
     */
    public static function reasons(User $user, ?TeacherProfile $profile, int $publishedCourses): array
    {
        $reasons = [];

        if ($user->role !== UserRole::Teacher) {
            $reasons[] = self::NOT_TEACHER;
        }

        // Tài khoản ẩn danh hoá coi như không dùng được (SQL loại `anonymized_at IS NOT NULL`).
        if ($user->status !== UserStatus::Active || $user->anonymized_at !== null) {
            $reasons[] = self::ACCOUNT_LOCKED;
        }

        if ($profile === null || ! $profile->show_on_homepage) {
            $reasons[] = self::NOT_ENABLED;
        }

        if ($profile === null || $profile->public_consent_at === null) {
            $reasons[] = self::NO_CONSENT;
        }

        if ($profile === null || $profile->avatar_path === null) {
            $reasons[] = self::NO_AVATAR;
        }

        // "Có bio" tính trên nội dung nhìn thấy được (bỏ khoảng trắng Unicode và ký tự ẩn), khớp REGEXP của HomepageTeacherQuery.
        if ($profile === null || VisibleText::isBlank($profile->bio)) {
            $reasons[] = self::NO_BIO;
        }

        if ($publishedCourses < 1) {
            $reasons[] = self::NO_PUBLISHED_COURSE;
        }

        return $reasons;
    }
}
