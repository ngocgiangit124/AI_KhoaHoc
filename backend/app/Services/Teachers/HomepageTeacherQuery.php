<?php

namespace App\Services\Teachers;

use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Teachers\Data\HomepageTeacher;
use App\Support\VisibleText;
use Illuminate\Support\Facades\DB;

/**
 * Giáo viên hiện ở trang chủ (US-020 BR2/BR3), đúng 2 câu SQL (không N+1): câu 1 lọc + sắp xếp + LIMIT, câu 2 tính
 * số khóa/các lớp cho ≤ `homepage_max` người. Không cache ở Laravel (ADR-005 §5). Điều kiện phải khớp
 * `TeacherEligibility::reasons()`. Kết quả vẫn đi qua `PublicTeacher` ở Resource (lớp kiểm thứ hai về đồng ý).
 */
class HomepageTeacherQuery
{
    /**
     * @return list<HomepageTeacher>
     */
    public function get(): array
    {
        $rows = User::query()
            ->join('teacher_profiles as tp', 'tp.user_id', '=', 'users.id')
            ->where('tp.show_on_homepage', true)
            ->whereNotNull('tp.public_consent_at')
            ->whereNotNull('tp.avatar_path')
            // "Có bio" = còn ký tự nhìn thấy được (bỏ khoảng trắng Unicode, ký tự ẩn): khớp `VisibleText::isBlank`.
            ->whereRaw('tp.bio REGEXP ?', [VisibleText::SQL_NOT_BLANK_PATTERN])
            ->where('users.role', UserRole::Teacher->value)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.anonymized_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('course_teacher as ct')
                ->join('courses as c', 'c.id', '=', 'ct.course_id')
                ->whereColumn('ct.user_id', 'users.id')
                ->where('c.status', CourseStatus::Published->value)
                ->whereNull('c.deleted_at'))
            ->orderByRaw('tp.homepage_order is null')
            ->orderBy('tp.homepage_order')
            ->orderBy('users.id')
            ->limit((int) config('teacher_profile.homepage_max'))
            ->get([
                'users.id', 'users.name', 'users.role',
                'tp.headline as tp_headline', 'tp.bio as tp_bio', 'tp.avatar_path as tp_avatar_path',
                'tp.public_consent_at as tp_public_consent_at',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $stats = DB::table('course_teacher as ct')
            ->join('courses as c', 'c.id', '=', 'ct.course_id')
            ->whereIn('ct.user_id', $rows->pluck('id')->all())
            ->where('c.status', CourseStatus::Published->value)
            ->whereNull('c.deleted_at')
            ->groupBy('ct.user_id')
            ->selectRaw('ct.user_id, COUNT(DISTINCT c.id) as courses_count, GROUP_CONCAT(DISTINCT c.grade_level ORDER BY c.grade_level) as grades')
            ->get()
            ->keyBy('user_id');

        $result = [];

        foreach ($rows as $user) {
            $profile = (new TeacherProfile)->forceFill([
                'user_id' => $user->id,
                'headline' => $user->getAttribute('tp_headline'),
                'bio' => $user->getAttribute('tp_bio'),
                'avatar_path' => $user->getAttribute('tp_avatar_path'),
                'public_consent_at' => $user->getAttribute('tp_public_consent_at'),
            ]);
            $user->setRelation('teacherProfile', $profile);

            $stat = $stats->get($user->id);
            $grades = $stat === null || $stat->grades === null
                ? []
                : array_map('intval', explode(',', (string) $stat->grades));

            $result[] = new HomepageTeacher($user, $grades, $stat === null ? 0 : (int) $stat->courses_count);
        }

        return $result;
    }
}
