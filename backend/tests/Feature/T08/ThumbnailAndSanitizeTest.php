<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Subject;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\ImageUploadService;
use App\Services\Courses\CourseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mews\Purifier\Facades\Purifier;

require_once __DIR__.'/helpers.php';

function vvTmpFileWith(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'vvt');
    file_put_contents($path, $content);

    return $path;
}

/** JPEG hợp lệ có đoạn EXIF (APP1) chứa chuỗi nhận diện. */
function vvJpegWithExif(string $marker = 'SECRET-GPS-1234'): UploadedFile
{
    $src = UploadedFile::fake()->image('a.jpg', 120, 90);
    $jpeg = (string) file_get_contents($src->getRealPath());
    $payload = "Exif\0\0".$marker;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return new UploadedFile(vvTmpFileWith(substr($jpeg, 0, 2).$app1.substr($jpeg, 2)), 'photo.jpg', 'image/jpeg', null, true);
}

function vvCreateWithThumb(mixed $file): TestResponse
{
    return vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [vvStaffUser('teacher')->id], 'thumbnail' => $file]));
}

test('S2: EXIF bi xoa, anh ma hoa lai thanh WebP, ten UUID', function () {
    vvCourseActor();
    $file = vvJpegWithExif();
    expect(file_get_contents($file->getRealPath()))->toContain('SECRET-GPS-1234');

    $res = vvCreateWithThumb($file)->assertCreated();
    $name = Course::query()->findOrFail($res->json('id'))->thumbnail_path;

    expect($name)->toMatch('/^[0-9a-f-]{36}\.webp$/');
    $bytes = Storage::disk('uploads')->get($name);
    expect(substr($bytes, 0, 4))->toBe('RIFF')->and(substr($bytes, 8, 4))->toBe('WEBP')
        ->and($bytes)->not->toContain('SECRET-GPS-1234')->and($bytes)->not->toContain('Exif');
});

test('S2: anh lon bi thu nho ve toi da 1600px', function () {
    vvCourseActor();
    $res = vvCreateWithThumb(UploadedFile::fake()->image('big.png', 3200, 1600))->assertCreated();
    $bytes = Storage::disk('uploads')->get(Course::query()->findOrFail($res->json('id'))->thumbnail_path);
    [$w, $h] = getimagesizefromstring($bytes);
    expect($w)->toBe(1600)->and($h)->toBe(800);
});

test('S2: SVG, HTML, GIF, PHP, polyglot, file rong, qua lon/to -> 422 va khong luu file', function () {
    vvCourseActor();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    $pngSrc = UploadedFile::fake()->image('a.png', 20, 20);
    $png = (string) file_get_contents($pngSrc->getRealPath());
    $cases = [
        'svg' => UploadedFile::fake()->createWithContent('a.svg', $svg),
        'svg doi duoi png' => UploadedFile::fake()->createWithContent('a.png', $svg),
        'svg doi duoi jpg + mime jpeg' => new UploadedFile(vvTmpFileWith($svg), 'a.jpg', 'image/jpeg', null, true),
        'html' => UploadedFile::fake()->createWithContent('a.html', '<html><script>alert(1)</script></html>'),
        'html doi duoi jpg' => UploadedFile::fake()->createWithContent('a.jpg', '<html><script>alert(1)</script></html>'),
        'gif' => UploadedFile::fake()->image('a.gif', 10, 10),
        'php' => UploadedFile::fake()->createWithContent('a.php', '<?php system($_GET["c"]);'),
        'polyglot png+php doi duoi php' => UploadedFile::fake()->createWithContent('a.php', $png.'<?php system("id");'),
        'file rong' => UploadedFile::fake()->create('a.jpg', 0),
        'qua 2MB' => UploadedFile::fake()->create('a.jpg', 2049, 'image/jpeg'),
        'qua 4000px' => UploadedFile::fake()->image('a.jpg', 4001, 10),
        'khong phai file' => 'not-a-file',
    ];

    foreach ($cases as $label => $file) {
        $res = vvCreateWithThumb($file);
        expect($res->status())->toBe(422, $label);
        expect($res->json('errors'))->toHaveKey('thumbnail');
    }

    expect(Storage::disk('uploads')->allFiles())->toBe([]);
});

test('S2: file giai ma loi (header JPEG nhung hong) -> ValidationException o service, khong luu', function () {
    Storage::fake('uploads');
    $file = new UploadedFile(vvTmpFileWith("\xFF\xD8\xFF\xE0garbage-not-a-jpeg"), 'a.jpg', 'image/jpeg', null, true);

    expect(fn () => app(ImageUploadService::class)->storeWebp($file))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
});

test('thay anh: file cu bi xoa, file moi la UUID khac; sua khong gui anh thi giu anh cu', function () {
    vvCourseActor();
    $id = vvCreateWithThumb(UploadedFile::fake()->image('1.jpg', 100, 100))->assertCreated()->json('id');
    $old = Course::query()->findOrFail($id)->thumbnail_path;

    vvCoursePost("/admin/courses/{$id}", ['title' => 'Chỉ đổi tên'], 'PUT')->assertOk();
    expect(Course::query()->findOrFail($id)->thumbnail_path)->toBe($old);

    vvCoursePost("/admin/courses/{$id}", ['thumbnail' => UploadedFile::fake()->image('2.png', 100, 100)], 'PUT')->assertOk();
    $new = Course::query()->findOrFail($id)->thumbnail_path;

    expect($new)->not->toBe($old);
    Storage::disk('uploads')->assertMissing($old);
    Storage::disk('uploads')->assertExists($new);
    expect(AuditLog::query()->where('action', 'course.update')->where('subject_id', $id)->latest('id')->first()->changes['fields'])->toContain('thumbnail');
});

test('client khong the ghi thumbnail_path (chuoi tuy y)', function () {
    vvCourseActor();
    $id = vvCreateWithThumb(UploadedFile::fake()->image('1.jpg', 50, 50))->assertCreated()->json('id');
    $old = Course::query()->findOrFail($id)->thumbnail_path;

    vvCourseJson('PUT', "/admin/courses/{$id}", ['thumbnail' => '../../x.png'])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$id}", ['thumbnail_path' => '../../etc/passwd'])->assertOk();

    expect(Course::query()->findOrFail($id)->thumbnail_path)->toBe($old);
});

test('tao that bai o service sau khi luu anh thi anh duoc don', function () {
    vvCourseActor();
    $service = app(CourseService::class);
    $admin = vvStaffUser('admin');
    $data = ['title' => 'T-clean', 'grade_level' => 9, 'price' => 0, 'description' => '<p>x</p>', 'subject_ids' => [Subject::factory()->create()->id]];

    expect(fn () => $service->create($data, UploadedFile::fake()->image('a.jpg', 20, 20), [999999], $admin))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([])->and(Course::query()->where('title', 'T-clean')->count())->toBe(0);
});

test('S8: description XSS bi loc khi ghi va khi doc; link an toan nhan rel/target', function () {
    vvCourseActor();
    $evil = '<p onclick="x()">Chào <strong>bạn</strong></p><script>alert(1)</script><img src=x onerror=alert(1)>'
        .'<iframe src="https://evil.test"></iframe><a href="javascript:alert(1)">a</a><a href="https://ok.test/x" onclick="y()" style="color:red">ok</a>'
        .'<style>*{display:none}</style><h1>nope</h1><h2 class="c" id="i">H2</h2><a href="data:text/html;base64,AAAA">d</a>';

    $res = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [vvStaffUser('teacher')->id], 'description' => $evil]))->assertCreated();
    $stored = (string) Course::query()->findOrFail($res->json('id'))->description;

    foreach ([$stored, $res->json('description')] as $html) {
        expect($html)->not->toContain('<script')->not->toContain('onerror')->not->toContain('onclick')
            ->not->toContain('<iframe')->not->toContain('javascript:')->not->toContain('<img')
            ->not->toContain('<style')->not->toContain('style=')->not->toContain('<h1')->not->toContain('class=')
            ->not->toContain('data:')->not->toContain('alert(1)')
            ->toContain('<strong>bạn</strong>')->toContain('<h2>H2</h2>')
            ->toContain('href="https://ok.test/x"')->toContain('target="_blank"')
            ->toContain('nofollow')->toContain('noopener')->toContain('noreferrer');
    }
});

test('S8: du lieu cu ghi thang vao DB van duoc loc lai khi tra ra', function () {
    vvCourseActor();
    $course = Course::factory()->create(['description' => '<p>ok</p>']);
    DB::table('courses')->where('id', $course->id)->update(['description' => '<p>ok</p><script>alert(1)</script>']);

    vvAdminGet("/admin/courses/{$course->id}")->assertOk()->assertJsonPath('description', '<p>ok</p>');
});

test('mo ta chi con the bi loai (rong sau khi loc) -> 422', function () {
    vvCourseActor();
    foreach (['<script>alert(1)</script>', '<img src=x>', '   ', '<p></p>'] as $desc) {
        $res = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [vvStaffUser('teacher')->id], 'description' => $desc]));
        expect($res->status())->toBe(422, $desc);
        expect($res->json('errors'))->toHaveKey('description');
    }
});

test('HtmlSanitizer: null/rong -> chuoi rong, mailto cho phep', function () {
    $s = app(HtmlSanitizer::class);
    expect($s->clean(null))->toBe('')->and($s->clean('  '))->toBe('')
        ->and($s->clean('<a href="mailto:a@b.vn">m</a>'))->toContain('mailto:a@b.vn');
});

test('R2: bien 4000x4000 duoc nhan va thu nho; thumbnail dang mang -> 422', function () {
    vvCourseActor();
    $res = vvCreateWithThumb(UploadedFile::fake()->image('max.png', 4000, 4000))->assertCreated();
    [$w, $h] = getimagesizefromstring(Storage::disk('uploads')->get(Course::query()->findOrFail($res->json('id'))->thumbnail_path));
    expect($w)->toBe(1600)->and($h)->toBe(1600);

    $before = count(Storage::disk('uploads')->allFiles());
    $r = vvCreateWithThumb([UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
    expect($r->status())->toBe(422)->and($r->json('errors'))->toHaveKey('thumbnail');
    expect(count(Storage::disk('uploads')->allFiles()))->toBe($before);
});

test('R2: WebP dong (ANIM/ANMF) khong gay 500; neu duoc nhan thi chi luu anh tinh', function () {
    vvCourseActor();
    $chunk = fn (string $id, string $data) => $id.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
    $still = (string) (function () {
        ob_start();
        imagewebp(imagecreatetruecolor(8, 8));

        return ob_get_clean();
    })();
    $vp8 = substr($still, 12); // các chunk của ảnh tĩnh (VP8 ...)
    $body = 'WEBP'.$chunk('VP8X', pack('C', 0x12)."\0\0\0".pack('v', 7)."\0".pack('v', 7)."\0")
        .$chunk('ANIM', "\0\0\0\0\0\0").$chunk('ANMF', str_repeat("\0", 12).$vp8);
    $file = new UploadedFile(vvTmpFileWith('RIFF'.pack('V', strlen($body)).$body), 'anim.webp', 'image/webp', null, true);

    $res = vvCreateWithThumb($file);
    expect($res->status())->toBeIn([201, 422]);

    if ($res->status() === 201) {
        $bytes = Storage::disk('uploads')->get(Course::query()->findOrFail($res->json('id'))->thumbnail_path);
        expect($bytes)->not->toContain('ANIM')->and($bytes)->not->toContain('ANMF');
    }
});

test('R3: mo ta chi co &nbsp; / U+00A0 / khoang trang Unicode -> 422', function () {
    vvCourseActor();
    foreach (['<p>&nbsp;</p>', "<p>\u{00A0}\u{00A0}</p>", "<p>\u{200B}\u{3000}\u{2003}</p>", '&nbsp;&nbsp;'] as $desc) {
        $res = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [vvStaffUser('teacher')->id], 'description' => $desc]));
        expect($res->status())->toBe(422, json_encode($desc));
        expect($res->json('errors'))->toHaveKey('description');
    }
});

test('R6: moi profile Purifier (ke ca default) deu chan img/style/script', function () {
    foreach (['default', HtmlSanitizer::PROFILE] as $profile) {
        $out = Purifier::clean('<p style="x:y">a</p><img src="https://x/y.png"><script>1</script>', $profile);
        expect($out)->not->toContain('<img')->not->toContain('style=')->not->toContain('<script');
    }
});
