<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;

function vvGetCourses(array $query = [])
{
    return test()->getJson('http://'.config('app.api_host').'/api/v1/courses?'.http_build_query($query), [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('GET /courses chi tra khoa published, phan trang 25/trang (BR2, BR4)', function () {
    Course::factory()->published()->count(3)->create();
    Course::factory()->create(); // draft
    Course::factory()->unpublished()->create();

    $response = vvGetCourses();

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
    $response->assertJsonPath('meta.per_page', 25);
});

test('GET /courses loc theo grade (AC1)', function () {
    Course::factory()->published()->create(['grade_level' => 8]);
    Course::factory()->published()->create(['grade_level' => 9]);

    $response = vvGetCourses(['grade' => 8]);

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.grade_level', 8);
});

test('GET /courses loc theo grade + chuyen de dong thoi (AC2)', function () {
    $hinhHoc = Subject::factory()->create(['name' => 'Hình học']);
    $daiSo = Subject::factory()->create(['name' => 'Đại số']);

    $match = Course::factory()->published()->create(['grade_level' => 8]);
    $match->subjects()->attach($hinhHoc);

    $wrongGrade = Course::factory()->published()->create(['grade_level' => 9]);
    $wrongGrade->subjects()->attach($hinhHoc);

    $wrongSubject = Course::factory()->published()->create(['grade_level' => 8]);
    $wrongSubject->subjects()->attach($daiSo);

    $response = vvGetCourses(['grade' => 8, 'subject_ids' => [$hinhHoc->id]]);

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $match->id);
});

test('GET /courses khong khop bo loc tra danh sach rong (AC3)', function () {
    Course::factory()->published()->create(['grade_level' => 6]);

    $response = vvGetCourses(['grade' => 12]);

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('GET /courses tim kiem khong dau, khong phan biet hoa thuong (AC5)', function () {
    $course = Course::factory()->published()->create([
        'title' => 'Hình học lớp 9',
        'short_description' => 'Ôn tập hình học',
    ]);
    Course::factory()->published()->create(['title' => 'Đại số lớp 9']);

    $response = vvGetCourses(['q' => 'hinh hoc lop 9']);

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $course->id);
});

/**
 * S24 — ký tự đặc biệt của LIKE (`%`, `_`) trong từ khoá không được làm sai
 * lệch kết quả (khớp mọi thứ) hay lỗi 500 (US-002 "Trường hợp biên & lỗi").
 */
test('GET /courses escape ky tu wildcard cua LIKE (S24)', function () {
    Course::factory()->published()->create(['title' => 'Khoá học A', 'short_description' => 'ABC']);
    Course::factory()->published()->create(['title' => 'Khoá học B', 'short_description' => 'XYZ']);

    $response = vvGetCourses(['q' => '100%']);

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('GET /courses tu khoa la ky tu dac biet khong gay loi 500', function () {
    Course::factory()->published()->create();

    $response = vvGetCourses(['q' => "'; DROP TABLE courses; --"]);

    $response->assertOk();
});

test('GET /courses sap xep newest theo published_at giam dan (mac dinh, AC7)', function () {
    $older = Course::factory()->published()->create(['published_at' => now()->subDays(2)]);
    $newer = Course::factory()->published()->create(['published_at' => now()->subDay()]);

    $response = vvGetCourses();

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $newer->id);
    $response->assertJsonPath('data.1.id', $older->id);
});

test('GET /courses sap xep popular theo enrollments_count giam dan (AC7)', function () {
    $popular = Course::factory()->published()->create(['enrollments_count' => 50]);
    $lessPopular = Course::factory()->published()->create(['enrollments_count' => 5]);

    $response = vvGetCourses(['sort' => 'popular']);

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $popular->id);
    $response->assertJsonPath('data.1.id', $lessPopular->id);
});

test('GET /courses sap xep featured uu tien manual_order (BR6)', function () {
    $manual = Course::factory()->published()->create(['manual_order' => 1, 'published_at' => now()->subDays(5)]);
    $noManual = Course::factory()->published()->create(['manual_order' => null, 'published_at' => now()]);

    $response = vvGetCourses(['sort' => 'featured']);

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $manual->id);
    $response->assertJsonPath('data.1.id', $noManual->id);
});

test('GET /courses tra Cache-Control public va khong tao Set-Cookie (S16)', function () {
    Course::factory()->published()->create();

    $response = vvGetCourses();

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([]);
});

test('GET /courses grade khong hop le (99) tra 422 thay vi crash', function () {
    $response = vvGetCourses(['grade' => 99]);

    $response->assertStatus(422);
});

test('GET /subjects chi tra chuyen de active (AC9)', function () {
    Subject::factory()->create(['name' => 'Hình học']);
    Subject::factory()->hidden()->create(['name' => 'Số học ẩn']);

    $response = test()->getJson('http://'.config('app.api_host').'/api/v1/subjects', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([]);
});

test('GET /courses/{slug} tra outline khong chua URL/ID video (S13, AC1)', function () {
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    Lesson::factory()->create([
        'course_id' => $course->id,
        'chapter_id' => $chapter->id,
        'position' => 1,
        'is_preview' => true,
    ]);

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([]);

    $body = $response->json();
    expect($body['outline'][0]['lessons'][0])
        ->toHaveKeys(['id', 'title', 'position', 'duration_seconds', 'is_preview'])
        ->not->toHaveKey('video_asset_id')
        ->not->toHaveKey('external_video_id')
        ->not->toHaveKey('video_source')
        ->not->toHaveKey('video_url');
});

test('GET /courses/{slug} chua co chuong/bai tra outline rong, khong loi (R4)', function () {
    $course = Course::factory()->published()->create();

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonPath('outline', []);
});

test('GET /courses/{slug} tra 404 khi khoa unpublished (BR4)', function () {
    $course = Course::factory()->unpublished()->create();

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertNotFound();
});

test('GET /courses/{slug} tra 404 khi khoa draft', function () {
    $course = Course::factory()->create();

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertNotFound();
});

test('GET /courses/{slug} tra 404 khi khoa da xoa mem (BR4, AC5)', function () {
    $course = Course::factory()->published()->create();
    $slug = $course->slug;
    $course->delete();

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertNotFound();
});

test('GET /courses/{slug} tra 404 khi slug khong ton tai', function () {
    $response = test()->getJson('http://'.config('app.api_host').'/api/v1/courses/khoa-hoc-khong-ton-tai', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertNotFound();
});

test('GET /courses/{slug} hien thi tat ca giao vien phu trach (AC6)', function () {
    $course = Course::factory()->published()->create();
    $teacher1 = User::factory()->teacher()->create();
    $teacher2 = User::factory()->teacher()->create();
    $course->teachers()->attach([$teacher1->id, $teacher2->id]);

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonCount(2, 'teachers');
});

test('GET /courses/{slug} hien thi so luong hoc sinh da dang ky (AC8)', function () {
    $course = Course::factory()->published()->create(['enrollments_count' => 42]);

    $response = test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}", [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJsonPath('enrollments_count', 42);
});
