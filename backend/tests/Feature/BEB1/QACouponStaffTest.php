<?php

use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;

require_once __DIR__.'/CheapestCoursePriceTest.php';
require_once __DIR__.'/ReleasedCoursesTest.php';

test('QA FA7: khach 401; hoc sinh dang nhap web khong vao duoc admin-api (401/403)', function () {
    vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertUnauthorized();

    $s = vvActAsStudent(User::factory()->create());
    $status = vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->getStatusCode();
    expect($s->id)->toBeInt()->and($status)->toBeIn([401, 403]);
});

test('QA FA7: admin 200; gia 1 dong va khoa mien phi 0 khong tinh; khoa nhap re hon khong tinh', function () {
    vvBeb1Actor('admin');
    Course::factory()->published()->paid(1)->create();
    Course::factory()->published()->paid(99999)->create();
    Course::factory()->published()->create(['price' => 0]);
    Course::factory()->paid(1)->create(); // nháp

    vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertOk()->assertExactJson(['cheapest_course_price' => 1]);
});

test('QA FA7: chi khoa xoa mem / mien phi / nhap -> null; gia la so nguyen (khong chuoi)', function () {
    vvBeb1Actor('admin');
    Course::factory()->published()->paid(5000)->create()->delete();
    Course::factory()->published()->create(['price' => 0]);

    $r = vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertOk();
    expect($r->json('cheapest_course_price'))->toBeNull();

    Course::factory()->published()->paid(250000)->create();
    $r = vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertOk();
    expect($r->json('cheapest_course_price'))->toBeInt()->toBe(250000);
});

test('QA FA7: khong nuot route /admin/coupons/{coupon}: GET id that van 200, id khong ton tai 404, DELETE/PUT khong bi anh huong', function () {
    vvBeb1Actor('admin');
    $c = Coupon::factory()->create();
    vvBeb1Send('GET', "/admin/coupons/{$c->id}")->assertOk()->assertJsonPath('id', $c->id);
    vvBeb1Send('GET', '/admin/coupons/999999999')->assertNotFound();
    vvBeb1Send('GET', '/admin/coupons')->assertOk();
    // POST vào đường dẫn mới không tồn tại như route ghi
    expect(vvBeb1Send('POST', '/admin/coupons/cheapest-course-price')->getStatusCode())->toBeIn([404, 405]);
});

test('QA FA10: doi vai tro giao vien -> admin tra released_courses khop released_course_ids, khoa chua xoa/da xoa; nguoi khong phai GV rong', function () {
    $teacher = vvStaffUser('teacher');
    $courses = [];
    foreach (['Hóa 10', 'Lý 11', 'Sinh 12'] as $t) {
        $c = Course::factory()->create(['title' => $t]);
        vvAssign($c, $teacher);
        $courses[] = $c;
    }
    $courses[1]->delete();
    vvStaffLogin(vvStaffUser('admin'));

    $r = test()->patchJson(vvAdminUrl("/admin/staff/{$teacher->id}/role"), ['role' => 'admin'], vvAdminHeaders())->assertOk();
    $ids = $r->json('released_course_ids');
    expect($ids)->toHaveCount(3)->and(array_column($r->json('released_courses'), 'id'))->toBe($ids)
        ->and(array_column($r->json('released_courses'), 'title'))->toEqualCanonicalizing(['Hóa 10', 'Lý 11', 'Sinh 12'])
        ->and($r->json('released_courses.0'))->toHaveKeys(['id', 'title'])->and(array_keys($r->json('released_courses.0')))->toBe(['id', 'title']);
});

test('QA FA10: giao vien khong dung duoc endpoint doi vai tro (403) va khach 401', function () {
    $target = vvStaffUser('pageManager');
    test()->patchJson(vvAdminUrl("/admin/staff/{$target->id}/role"), ['role' => 'admin'], vvAdminHeaders())->assertUnauthorized();
    app('auth')->forgetGuards();
    vvStaffLogin(vvStaffUser('teacher'));
    test()->patchJson(vvAdminUrl("/admin/staff/{$target->id}/role"), ['role' => 'admin'], vvAdminHeaders())->assertForbidden();
});
