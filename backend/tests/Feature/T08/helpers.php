<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T28/helpers.php';

/** Đăng nhập quản trị thật rồi trả user. */
function vvCourseActor(string $state = 'admin'): User
{
    Storage::fake('uploads');
    $user = vvStaffUser($state);
    vvStaffLogin($user);

    return $user;
}

function vvCourseJson(string $method, string $path, array $data = []): TestResponse
{
    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

/** Multipart (có file). PUT dùng POST + `_method` vì PHP không parse multipart của PUT. */
function vvCoursePost(string $path, array $data, string $method = 'POST'): TestResponse
{
    if ($method !== 'POST') {
        $data['_method'] = $method;
    }

    return test()->post(vvAdminUrl($path), $data, vvAdminHeaders(['Accept' => 'application/json']));
}

/** @return array<string, mixed> payload hợp lệ để tạo khóa (staff cần truyền teacher_ids). */
function vvCoursePayload(array $over = []): array
{
    $subject = Subject::factory()->create();

    return array_merge([
        'title' => 'Toán 9 nâng cao',
        'grade_level' => 9,
        'subject_ids' => [$subject->id],
        'short_description' => 'Ôn thi vào lớp 10',
        'description' => '<p>Nội dung <strong>chi tiết</strong></p>',
        'price' => 299000,
        'thumbnail' => UploadedFile::fake()->image('bia.jpg', 800, 600),
    ], $over);
}

function vvAssign(Course $course, User ...$teachers): Course
{
    foreach ($teachers as $teacher) {
        DB::table('course_teacher')->insert(['course_id' => $course->id, 'user_id' => $teacher->id]);
    }

    return $course;
}

function vvCourseWithContent(array $attrs = []): Course
{
    $course = Course::factory()->create($attrs);
    $chapter = Chapter::factory()->for($course)->create();
    Lesson::factory()->for($course)->for($chapter)->create();

    return $course;
}

function vvEnroll(Course $course, string $state = 'active'): Enrollment
{
    $factory = Enrollment::factory()->for($course);

    return match ($state) {
        'pending' => $factory->pendingApproval()->create(),
        'rejected' => $factory->rejected()->create(),
        default => $factory->create(),
    };
}
