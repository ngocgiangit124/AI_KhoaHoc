<?php

use App\Models\AuditLog;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ImageUploadService;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const VV_T36_AVATAR = '/admin/me/teacher-profile/avatar';

function vvT36UploadedFiles(): array
{
    return Storage::disk('uploads')->allFiles();
}

test('AC2: jpg chu nhat -> WebP vuong <= 800px, cat giua, avatar_url cap nhat, ten UUID, EXIF bi xoa', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    $file = vvT36JpegWithExif('GPS-SECRET-77', 1200, 600);
    expect(file_get_contents($file->getRealPath()))->toContain('GPS-SECRET-77');

    $r = vvT36Upload(VV_T36_AVATAR, $file)->assertOk();

    $name = vvT36Profile($t)->avatar_path;
    expect($name)->toMatch('/^[0-9a-f-]{36}\.webp$/');
    expect($r->json('avatar_url'))->toBe('https://static.example.test/'.$name);
    $bytes = Storage::disk('uploads')->get($name);
    expect(substr($bytes, 0, 4))->toBe('RIFF')->and(substr($bytes, 8, 4))->toBe('WEBP')
        ->and($bytes)->not->toContain('GPS-SECRET-77')->and($bytes)->not->toContain('Exif');
    [$w, $h] = getimagesizefromstring($bytes);
    expect($w)->toBe($h)->and($w)->toBe(600); // min(1200, 600) = 600, không phóng to
});

test('anh lon bi thu nho ve toi da 800px vuong; anh nho khong bi phong to; anh doc bi cat vuong', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    foreach ([[3000, 2000, 800], [100, 60, 60], [500, 900, 500], [800, 800, 800]] as [$w, $h, $expected]) {
        vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('a.png', $w, $h))->assertOk();
        [$rw, $rh] = getimagesizefromstring(Storage::disk('uploads')->get(vvT36Profile($t)->avatar_path));
        expect([$rw, $rh])->toBe([$expected, $expected], "{$w}x{$h}");
    }
});

test('AC2: thay anh -> file cu bi xoa, file moi la UUID khac, dung 1 file con lai; audit avatar changed', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('1.jpg', 300, 300))->assertOk();
    $old = vvT36Profile($t)->avatar_path;

    vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('2.png', 300, 300))->assertOk();

    $new = vvT36Profile($t)->avatar_path;
    expect($new)->not->toBe($old);
    Storage::disk('uploads')->assertMissing($old);
    Storage::disk('uploads')->assertExists($new);
    expect(vvT36UploadedFiles())->toHaveCount(1);

    $audit = AuditLog::query()->where('action', 'teacher_profile.update')->where('subject_id', $t->id)->orderByDesc('id')->first();
    expect($audit->changes)->toEqual(['fields' => ['avatar'], 'on_behalf' => false, 'avatar' => 'changed']);
    expect(json_encode($audit->changes))->not->toContain($new);
});

test('xoa anh: file bi xoa khoi kho, avatar_url null, audit removed; xoa lan hai 200 khong audit', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('1.jpg', 300, 300))->assertOk();
    $old = vvT36Profile($t)->avatar_path;
    $base = AuditLog::query()->where('action', 'teacher_profile.update')->where('subject_id', $t->id)->count();

    vvT36Json('DELETE', VV_T36_AVATAR)->assertOk()->assertJsonPath('avatar_url', null);

    Storage::disk('uploads')->assertMissing($old);
    expect(vvT36Profile($t)->avatar_path)->toBeNull();
    $audit = AuditLog::query()->where('action', 'teacher_profile.update')->where('subject_id', $t->id)->orderByDesc('id')->first();
    expect($audit->changes)->toEqual(['fields' => ['avatar'], 'on_behalf' => false, 'avatar' => 'removed']);

    vvT36Json('DELETE', VV_T36_AVATAR)->assertOk();
    expect(AuditLog::query()->where('action', 'teacher_profile.update')->where('subject_id', $t->id)->count())->toBe($base + 1);
});

test('xoa anh khi chua co dong ho so -> 200, khong tao dong', function () {
    vvT36Env();
    vvT36Login('teacher');

    vvT36Json('DELETE', VV_T36_AVATAR)->assertOk()->assertJsonPath('avatar_url', null);

    expect(TeacherProfile::query()->count())->toBe(0);
});

test('(e) SVG, GIF, HTML, PHP, polyglot, rong, > 2 MB, > 4000px, khong phai file -> 422 co loi avatar, KHONG co file nao tren disk', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    $pngSrc = UploadedFile::fake()->image('a.png', 20, 20);
    $png = (string) file_get_contents($pngSrc->getRealPath());
    $cases = [
        'svg' => UploadedFile::fake()->createWithContent('a.svg', $svg),
        'svg doi duoi png' => UploadedFile::fake()->createWithContent('a.png', $svg),
        'svg doi duoi jpg + mime jpeg' => new UploadedFile(vvT36TmpFile($svg), 'a.jpg', 'image/jpeg', null, true),
        'html' => UploadedFile::fake()->createWithContent('a.html', '<html><script>alert(1)</script></html>'),
        'html doi duoi jpg' => UploadedFile::fake()->createWithContent('a.jpg', '<html><script>alert(1)</script></html>'),
        'gif' => UploadedFile::fake()->image('a.gif', 10, 10),
        'gif doi duoi png' => UploadedFile::fake()->createWithContent('a.png', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"),
        'php' => UploadedFile::fake()->createWithContent('a.php', '<?php system($_GET["c"]);'),
        'polyglot png+php doi duoi php' => UploadedFile::fake()->createWithContent('a.php', $png.'<?php system("id");'),
        'file rong' => UploadedFile::fake()->create('a.jpg', 0),
        'qua 2MB' => UploadedFile::fake()->create('a.jpg', 2049, 'image/jpeg'),
        'qua 4000px' => UploadedFile::fake()->image('a.jpg', 4001, 10),
        'khong phai file' => 'not-a-file',
    ];

    foreach ($cases as $label => $file) {
        // Lần tải lỗi cũng tính vào throttle 10/phút (chống dò); xoá bộ đếm để xét từng ca.
        app('cache')->store((string) config('cache.limiter'))->flush();
        $res = vvT36Upload(VV_T36_AVATAR, $file);
        expect($res->status())->toBe(422, $label);
        expect($res->json('errors'))->toHaveKey('avatar');
    }
    // Thiếu field avatar.
    app('cache')->store((string) config('cache.limiter'))->flush();
    vvT36Json('POST', VV_T36_AVATAR, [])->assertStatus(422)->assertJsonValidationErrors(['avatar']);

    expect(vvT36UploadedFiles())->toBe([]);
    expect(TeacherProfile::query()->find($t->id)?->avatar_path)->toBeNull();
});

test('(e) thong bao loi tieng Viet cu the cho dinh dang, dung luong, kich thuoc', function () {
    vvT36Env();
    vvT36Login('teacher');
    app('cache')->store((string) config('cache.limiter'))->flush();

    expect(vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('a.gif', 10, 10))->json('errors.avatar.0'))->toContain('JPG, PNG hoặc WebP');
    expect(vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->create('a.jpg', 2049, 'image/jpeg'))->json('errors.avatar.0'))->toContain('2 MB');
    expect(vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('a.jpg', 4001, 10))->json('errors.avatar.0'))->toContain('4000x4000');
});

test('file giai ma loi (header JPEG nhung hong) -> ValidationException o service, khong luu, khong doi ho so', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    $file = new UploadedFile(vvT36TmpFile("\xFF\xD8\xFF\xE0garbage-not-a-jpeg"), 'a.jpg', 'image/jpeg', null, true);

    expect(fn () => app(TeacherProfileService::class)->replaceAvatar($t, $file, $t))->toThrow(ValidationException::class);

    expect(vvT36UploadedFiles())->toBe([])->and(vvT36Profile($t))->toBeNull();
});

test('loi trong transaction -> file moi bi xoa, anh cu giu nguyen', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('1.jpg', 300, 300))->assertOk();
    $old = vvT36Profile($t)->avatar_path;

    // Làm audit ném lỗi sau khi file mới đã ghi: transaction rollback.
    app()->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
        {
            throw new RuntimeException('audit down');
        }
    });

    $service = app(TeacherProfileService::class);
    expect(fn () => $service->replaceAvatar($t, UploadedFile::fake()->image('2.jpg', 300, 300), $t))->toThrow(RuntimeException::class, 'audit down');

    expect(vvT36Profile($t)->avatar_path)->toBe($old);
    Storage::disk('uploads')->assertExists($old);
    expect(vvT36UploadedFiles())->toHaveCount(1);
});

test('upload avatar: rate limit 10/phut/nguoi -> 429', function () {
    vvT36Env();
    vvT36Login('teacher');

    foreach (range(1, 10) as $i) {
        vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('a.jpg', 50, 50))->assertOk();
    }
    vvT36Upload(VV_T36_AVATAR, UploadedFile::fake()->image('a.jpg', 50, 50))->assertStatus(429);
});

test('ImageUploadService: khong truyen squareEdge thi giu hanh vi cu cua thumbnail (<= 1600px, giu ti le)', function () {
    Storage::fake('uploads');
    $name = app(ImageUploadService::class)->storeWebp(UploadedFile::fake()->image('big.png', 3200, 1600));

    [$w, $h] = getimagesizefromstring(Storage::disk('uploads')->get($name));

    expect([$w, $h])->toBe([1600, 800]);
});
