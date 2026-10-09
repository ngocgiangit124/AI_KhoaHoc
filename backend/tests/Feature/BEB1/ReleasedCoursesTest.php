<?php

use App\Models\Course;

require_once __DIR__.'/../T28/helpers.php';
require_once __DIR__.'/../T08/helpers.php';

test('BE-backlog-1 FA10: PATCH role tra released_courses [{id,title}] cung thu tu voi released_course_ids (ke ca khoa da xoa mem)', function () {
    $teacher = vvStaffUser('teacher');
    $c1 = Course::factory()->create(['title' => 'Toán 9 nâng cao']);
    $c2 = Course::factory()->create(['title' => 'Văn 8']);
    vvAssign($c1, $teacher);
    vvAssign($c2, $teacher);
    $c2->delete();
    vvStaffLogin(vvStaffUser('admin'));

    test()->patchJson(vvAdminUrl("/admin/staff/{$teacher->id}/role"), ['role' => 'quan_ly_trang'], vvAdminHeaders())
        ->assertOk()
        ->assertJsonPath('released_course_ids', [$c1->id, $c2->id])
        ->assertJsonPath('released_courses', [['id' => $c1->id, 'title' => 'Toán 9 nâng cao'], ['id' => $c2->id, 'title' => 'Văn 8']]);
});

test('BE-backlog-1 FA10: khong phai giao vien hoac khong co khoa -> released_courses = []', function () {
    $manager = vvStaffUser('pageManager');
    $teacher = vvStaffUser('teacher');
    vvStaffLogin(vvStaffUser('admin'));

    test()->patchJson(vvAdminUrl("/admin/staff/{$manager->id}/role"), ['role' => 'admin'], vvAdminHeaders())
        ->assertOk()->assertJsonPath('released_courses', [])->assertJsonPath('released_course_ids', []);
    app('auth')->forgetGuards();
    test()->patchJson(vvAdminUrl("/admin/staff/{$teacher->id}/role"), ['role' => 'admin'], vvAdminHeaders())
        ->assertOk()->assertJsonPath('released_courses', []);
});
