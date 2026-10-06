<?php

namespace App\Services\Teachers\Data;

use App\Models\User;

/**
 * Một giáo viên đủ điều kiện ở trang chủ. `user` đã gắn quan hệ `teacherProfile` (đi qua `PublicTeacher`).
 */
final readonly class HomepageTeacher
{
    /**
     * @param  list<int>  $gradeLevels  các lớp khác nhau của khóa published, tăng dần
     */
    public function __construct(
        public User $user,
        public array $gradeLevels,
        public int $coursesCount,
    ) {}
}
