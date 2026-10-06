<?php

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

/**
 * R1 (review T36): test mức route. Duyệt MỌI route GET công khai (host api, không cần đăng nhập) liên quan tới khóa học hoặc
 * giáo viên, gọi với giáo viên CHƯA đồng ý (có bio, headline, ảnh, cờ trang chủ) và khẳng định response không chứa bio,
 * headline hay tên file ảnh. Route mới thêm sau này có tham số lạ sẽ làm test này fail để buộc cập nhật.
 */
const VV_T36_BIO = 'BIO-RIENG-TU-XYZ-7781';
const VV_T36_HEADLINE = 'HEADLINE-RIENG-TU-XYZ-7781';
const VV_T36_AVATAR_FILE = 'cccccccc-1111-4222-8333-444444444444.webp';

/** @return list<Route> */
function vvT36PublicRelatedRoutes(): array
{
    $apiHost = (string) config('app.api_host');
    $found = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || $route->getDomain() !== $apiHost) {
            continue;
        }

        $uri = $route->uri();
        if (! str_contains($uri, 'courses') && ! str_contains($uri, 'teachers')) {
            continue;
        }

        $guarded = collect($route->gatherMiddleware())->contains(
            fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'student') || str_starts_with($m, 'account.') || str_starts_with($m, 'role:'))
        );

        if (! $guarded) {
            $found[] = $route;
        }
    }

    return $found;
}

test('R1: tim thay cac route cong khai ve khoa hoc/giao vien (khong rong, co /courses, /courses/{slug}, /home/teachers)', function () {
    $uris = array_map(fn (Route $r) => $r->uri(), vvT36PublicRelatedRoutes());

    expect($uris)->toContain('api/v1/courses')->toContain('api/v1/courses/{slug}')->toContain('api/v1/home/teachers');
    // viewer-state cần đăng nhập nên không thuộc nhóm công khai.
    expect($uris)->not->toContain('api/v1/courses/{slug}/viewer-state');
});

test('R1: moi route GET cong khai ve khoa hoc/giao vien khong lo bio, headline, ten file anh cua giao vien CHUA dong y', function () {
    config(['app.static_url' => 'https://static.example.test']);

    $hidden = User::factory()->teacher()->create(['name' => 'Giáo viên kín']);
    TeacherProfile::factory()->create([
        'user_id' => $hidden->id, 'headline' => VV_T36_HEADLINE, 'bio' => VV_T36_BIO, 'avatar_path' => VV_T36_AVATAR_FILE,
        'show_on_homepage' => true, 'homepage_order' => 1,
    ]);
    $course = vvT36PublishedCourse($hidden);

    // Đối chứng dương: giáo viên đã đồng ý thì chính các route đó có hiện bio (chứng minh test không rỗng).
    $open = vvT36EligibleTeacher(['name' => 'Giáo viên mở']);
    $openProfile = vvT36Profile($open);
    $openCourse = $open->taughtCourses()->first();

    $calls = 0;
    $positive = 0;

    foreach (vvT36PublicRelatedRoutes() as $route) {
        $uri = $route->uri();
        $params = $route->parameterNames();
        $unknown = array_diff($params, ['slug']);
        expect($unknown)->toBe([], "Route công khai mới `{$uri}` có tham số lạ: cập nhật test R1 để gọi nó với dữ liệu giáo viên chưa đồng ý.");

        $urls = [str_replace('{slug}', $course->slug, preg_replace('~^api/v1~', '', $uri))];
        if ($uri === 'api/v1/courses') {
            $urls[] = "/courses?teacher_id={$hidden->id}";
            $urls[] = '/courses?sort=featured&grade='.$course->grade_level;
            $urls[] = '/courses?q='.rawurlencode(Str::ascii($course->title));
        }

        foreach ($urls as $url) {
            $res = vvT36Public($url);
            expect($res->status())->toBeIn([200, 304], "{$url} -> {$res->status()}");
            $raw = $res->getContent();
            foreach ([VV_T36_BIO, VV_T36_HEADLINE, VV_T36_AVATAR_FILE, 'cccccccc-1111'] as $secret) {
                expect($raw)->not->toContain($secret, "{$url} lộ `{$secret}` của giáo viên chưa đồng ý");
            }
            $calls++;
        }

        // Đối chứng dương trên route chi tiết/trang chủ.
        $openUrl = str_replace('{slug}', $openCourse->slug, preg_replace('~^api/v1~', '', $uri));
        if (in_array($uri, ['api/v1/courses/{slug}', 'api/v1/home/teachers'], true)) {
            expect(vvT36Public($openUrl)->getContent())->toContain((string) $openProfile->avatar_path);
            $positive++;
        }
    }

    expect($calls)->toBeGreaterThanOrEqual(5)->and($positive)->toBe(2);
});
