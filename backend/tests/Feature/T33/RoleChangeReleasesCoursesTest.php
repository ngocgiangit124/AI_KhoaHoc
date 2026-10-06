<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Course;
use App\Services\Courses\CourseTeacherService;
use App\Services\Staff\StaffAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../T28/helpers.php';
require_once __DIR__.'/../T08/helpers.php';

test('T33-4: doi giao vien sang vai tro khac go moi dong course_teacher cua ho, khong dong toi giao vien khac', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');
    $other = vvStaffUser('teacher');
    $c1 = Course::factory()->create();
    $c2 = Course::factory()->create();
    vvAssign($c1, $teacher, $other);
    vvAssign($c2, $teacher);

    app(StaffAccountService::class)->changeRole($teacher, UserRole::PageManager, $admin);

    expect(DB::table('course_teacher')->where('user_id', $teacher->id)->count())->toBe(0)
        ->and(DB::table('course_teacher')->where('user_id', $other->id)->where('course_id', $c1->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'staff.role_change')->latest('id')->firstOrFail();
    expect($log->changes['released_course_ids'])->toBe([$c1->id, $c2->id]);
});

test('T33-4: doi tu vai tro khac (khong phai giao vien) khong dung toi course_teacher va audit khong co released', function () {
    $admin = vvStaffUser('admin');
    $manager = vvStaffUser('pageManager');

    app(StaffAccountService::class)->changeRole($manager, UserRole::Admin, $admin);

    $log = AuditLog::query()->where('action', 'staff.role_change')->latest('id')->firstOrFail();
    expect($log->changes)->not->toHaveKey('released_course_ids');
});

test('R5: PATCH role tra released_course_ids de UI canh bao; doi tu vai tro khac thi mang rong', function () {
    $teacher = vvStaffUser('teacher');
    $c1 = Course::factory()->create();
    vvAssign($c1, $teacher);
    vvStaffLogin(vvStaffUser('admin'));

    $res = test()->patchJson(vvAdminUrl("/admin/staff/{$teacher->id}/role"), ['role' => 'quan_ly_trang'], vvAdminHeaders());
    $res->assertOk()->assertJsonPath('role', 'quan_ly_trang')->assertJsonPath('released_course_ids', [$c1->id]);

    app('auth')->forgetGuards();
    test()->patchJson(vvAdminUrl("/admin/staff/{$teacher->id}/role"), ['role' => 'admin'], vvAdminHeaders())
        ->assertOk()->assertJsonPath('released_course_ids', []);
});

test('R4: sync tu choi giao vien vua bi doi vai tro (role doc co khoa chia se)', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');
    $keep = vvStaffUser('teacher');
    $course = Course::factory()->create();
    vvAssign($course, $keep);

    app(StaffAccountService::class)->changeRole($teacher, UserRole::PageManager, $admin);

    expect(fn () => app(CourseTeacherService::class)->sync($course, [$keep->id, $teacher->id], $admin))
        ->toThrow(ValidationException::class);
    expect(DB::table('course_teacher')->where('user_id', $teacher->id)->count())->toBe(0);
});
