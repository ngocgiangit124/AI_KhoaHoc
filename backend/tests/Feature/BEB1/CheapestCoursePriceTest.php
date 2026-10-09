<?php

use App\Models\Course;

require_once __DIR__.'/../T28/helpers.php';

function vvBeb1Actor(string $state = 'admin')
{
    $user = vvStaffUser($state);
    vvStaffLogin($user);

    return $user;
}

function vvBeb1Send(string $method, string $path, array $data = [])
{
    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

test('BE-backlog-1 FA7: chua co khoa co phi dang ban -> null', function () {
    vvBeb1Actor('admin');
    Course::factory()->published()->create(['price' => 0]);
    Course::factory()->paid(100000)->create(); // nháp

    vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertOk()->assertExactJson(['cheapest_course_price' => null]);
});

test('BE-backlog-1 FA7: gia re nhat trong cac khoa published, co phi, chua xoa mem (bo qua nhap/go xuat ban/mien phi)', function () {
    vvBeb1Actor('pageManager');
    Course::factory()->published()->paid(300000)->create();
    Course::factory()->published()->paid(120000)->create();
    Course::factory()->published()->create(['price' => 0]);
    Course::factory()->paid(1000)->create();
    Course::factory()->unpublished()->paid(2000)->create();
    Course::factory()->published()->paid(5000)->create()->delete();

    vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertOk()->assertExactJson(['cheapest_course_price' => 120000]);
});

test('BE-backlog-1 FA7: giao vien 403, khach 401; khong nuot route /admin/coupons/{coupon}', function () {
    vvBeb1Actor('teacher');
    vvBeb1Send('GET', '/admin/coupons/cheapest-course-price')->assertForbidden();
});
