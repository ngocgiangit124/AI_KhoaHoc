<?php

use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T28/helpers.php';

/** Chuẩn bị môi trường: disk uploads giả, STATIC_URL để dựng avatar_url. */
function vvT36Env(): void
{
    Storage::fake('uploads');
    config(['app.static_url' => 'https://static.example.test']);
}

/** @param  'admin'|'pageManager'|'teacher'  $state */
function vvT36Login(string $state, array $attrs = []): User
{
    $user = vvStaffUser($state, $attrs);
    vvStaffLogin($user);

    return $user;
}

function vvT36Json(string $method, string $path, array $data = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

function vvT36Upload(string $path, mixed $file): TestResponse
{
    app('auth')->forgetGuards();

    return test()->post(vvAdminUrl($path), ['avatar' => $file], vvAdminHeaders(['Accept' => 'application/json']));
}

function vvT36Public(string $path): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson(vvApiUrl($path));
}

function vvT36PublishedCourse(?User $teacher = null, array $attrs = []): Course
{
    $course = Course::factory()->published()->create($attrs);

    if ($teacher !== null) {
        DB::table('course_teacher')->insert(['course_id' => $course->id, 'user_id' => $teacher->id]);
    }

    return $course;
}

/** Giáo viên đủ mọi điều kiện hiện ở trang chủ (đồng ý, ảnh, bio, bật cờ, có khóa published). */
function vvT36EligibleTeacher(array $userAttrs = [], ?int $order = null, array $courseAttrs = []): User
{
    $teacher = User::factory()->teacher()->withPublicProfile(true, $order)->create($userAttrs);
    vvT36PublishedCourse($teacher, $courseAttrs);

    return $teacher;
}

function vvT36Profile(User $user): ?TeacherProfile
{
    return TeacherProfile::query()->find($user->id);
}

/** Cập nhật cột hồ sơ trực tiếp (bỏ qua service) để dựng ma trận điều kiện. */
function vvT36SetProfile(User $user, array $attrs): void
{
    TeacherProfile::query()->whereKey($user->id)->update($attrs);
}

function vvT36SetUser(User $user, array $attrs): void
{
    User::query()->whereKey($user->id)->update($attrs);
}

function vvT36TmpFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'vvt36');
    file_put_contents($path, $content);

    return $path;
}

/** JPEG chữ nhật có đoạn EXIF chứa chuỗi nhận diện. */
function vvT36JpegWithExif(string $marker = 'GPS-SECRET-77', int $w = 1200, int $h = 600): UploadedFile
{
    $src = UploadedFile::fake()->image('a.jpg', $w, $h);
    $jpeg = (string) file_get_contents($src->getRealPath());
    $payload = "Exif\0\0".$marker;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return new UploadedFile(vvT36TmpFile(substr($jpeg, 0, 2).$app1.substr($jpeg, 2)), 'photo.jpg', 'image/jpeg', null, true);
}

function vvT36Lock(User $user): void
{
    User::query()->whereKey($user->id)->update(['status' => UserStatus::Locked->value]);
}
