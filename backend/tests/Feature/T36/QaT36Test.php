<?php

use App\Mail\TeacherProfileEditedByStaffMail;
use App\Models\Consent;
use App\Models\Course;
use App\Models\User;
use App\Services\Teachers\TeacherEligibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * QA T36 (US-020): các ca còn thiếu theo gợi ý của review và security.
 * - Ảnh: EXIF orientation, biên 2 MB, PNG alpha, WebP động.
 * - Hai thao tác chéo nhau (rút đồng ý / admin sửa hộ) và /courses/{slug} phải null ngay.
 * - Đổi phiên bản đồng ý, ETag/304, điều kiện BR2 còn thiếu (xoá mềm đồng giảng, ẩn danh hoá, khoá).
 * - "Chặn oan" của PlainText ở API admin thật (tên khóa, mô tả ngắn, chương, bài, tên mã giảm giá).
 */
require_once __DIR__.'/helpers.php';

const VV_QAT36_ME = '/admin/me/teacher-profile';

// ---------------------------------------------------------------- helper (tiền tố vvQaT36, tránh trùng tên)

/** JPEG 400x200 (nửa trái đỏ, nửa phải xanh) có EXIF Orientation = $orientation (TIFF thật, không chỉ chuỗi nhận diện). */
function vvQaT36OrientedJpeg(int $orientation): UploadedFile
{
    $im = imagecreatetruecolor(400, 200);
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, 200, 0, 399, 199, imagecolorallocate($im, 0, 0, 255));
    ob_start();
    imagejpeg($im, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = "II*\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\x00\x00".pack('V', 0);
    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return new UploadedFile(vvT36TmpFile(substr($jpeg, 0, 2).$app1.substr($jpeg, 2)), 'o.jpg', 'image/jpeg', null, true);
}

/** JPEG hợp lệ, đệm số 0 sau EOI đến đúng $bytes byte (giải mã vẫn được). */
function vvQaT36JpegOfSize(int $bytes): UploadedFile
{
    $src = UploadedFile::fake()->image('a.jpg', 200, 200); // giữ tham chiếu: tệp tạm bị xoá khi đối tượng bị huỷ
    $jpeg = (string) file_get_contents($src->getRealPath());

    return new UploadedFile(vvT36TmpFile($jpeg.str_repeat("\x00", $bytes - strlen($jpeg))), 'big.jpg', 'image/jpeg', null, true);
}

/** @return array{0:int,1:int,2:int} [r,g,b] của điểm ảnh trong WebP đã lưu. */
function vvQaT36Pixel(string $webpBytes, int $x, int $y): array
{
    $im = imagecreatefromstring($webpBytes);
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
}

/** WebP "động" tối thiểu (VP8X cờ animation + ANIM + 2 ANMF). */
function vvQaT36AnimatedWebp(): UploadedFile
{
    $im = imagecreatetruecolor(64, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    imagewebp($im, null, 80);
    $chunk = substr((string) ob_get_clean(), 12);
    $frame = 'ANMF'.pack('V', 16 + strlen($chunk)).pack('v', 0)."\0".pack('v', 0)."\0".pack('v', 63)."\0".pack('v', 63)."\0".pack('v', 100)."\0\0".$chunk;
    $body = 'WEBP'.'VP8X'.pack('V', 10)."\x02\0\0\0".pack('v', 63)."\0".pack('v', 63)."\0".'ANIM'.pack('V', 6)."\0\0\0\0\0\0".$frame.$frame;

    return new UploadedFile(vvT36TmpFile('RIFF'.pack('V', strlen($body)).$body), 'a.webp', 'image/webp', null, true);
}

/**
 * Đăng nhập người khác. Phải đăng nhập admin TRƯỚC khi gọi API công khai đầu tiên trong test: sau request tới host `api`
 * app test (một tiến trình) dùng tên cookie `vv_session` nên login admin kế tiếp không nhận được `vv_admin_session`.
 */
function vvQaT36LoginAs(User $user): void
{
    vvStaffLogin($user);
}

function vvQaT36HomeIds(): array
{
    return collect(vvT36Public('/home/teachers')->assertOk()->json('data'))->pluck('id')->all();
}

function vvQaT36Version(): string
{
    return (string) config('teacher_profile.consent_version');
}

// ---------------------------------------------------------------- Ảnh

test('QA AC2: EXIF Orientation=6 duoc xoay truoc khi cat vuong (cat dung giua anh da xoay, khong cat theo khung goc)', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Upload(VV_QAT36_ME.'/avatar', vvQaT36OrientedJpeg(6))->assertOk();

    $bytes = Storage::disk('uploads')->get(vvT36Profile($t)->avatar_path);
    [$w, $h] = getimagesizefromstring($bytes);
    expect([$w, $h])->toBe([200, 200]);
    // Xoay 90 do CW: nua trai (do) len tren, nua phai (xanh) xuong duoi.
    [$r1, , $b1] = vvQaT36Pixel($bytes, 100, 20);
    [$r2, , $b2] = vvQaT36Pixel($bytes, 100, 180);
    expect($r1)->toBeGreaterThan(200)->and($b1)->toBeLessThan(80);
    expect($b2)->toBeGreaterThan(200)->and($r2)->toBeLessThan(80);
});

test('QA AC2: EXIF Orientation=1 (binh thuong) giu nguyen: do ben trai, xanh ben phai', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Upload(VV_QAT36_ME.'/avatar', vvQaT36OrientedJpeg(1))->assertOk();

    $bytes = Storage::disk('uploads')->get(vvT36Profile($t)->avatar_path);
    [$r1, , $b1] = vvQaT36Pixel($bytes, 20, 100);
    [$r2, , $b2] = vvQaT36Pixel($bytes, 180, 100);
    expect($r1)->toBeGreaterThan(200)->and($b1)->toBeLessThan(80)->and($b2)->toBeGreaterThan(200)->and($r2)->toBeLessThan(80);
});

test('QA AC3: bien 2 MB: dung 2048 KB (2.097.152 byte) duoc nhan, them 1 byte -> 422 thong bao tieng Viet, khong luu gi', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Upload(VV_QAT36_ME.'/avatar', vvQaT36JpegOfSize(2048 * 1024))->assertOk();
    expect(Storage::disk('uploads')->allFiles())->toHaveCount(1);
    $kept = Storage::disk('uploads')->allFiles();

    app('cache')->store((string) config('cache.limiter'))->flush();
    vvT36Upload(VV_QAT36_ME.'/avatar', vvQaT36JpegOfSize(2048 * 1024 + 1))->assertStatus(422)->assertJsonPath('errors.avatar.0', 'Ảnh đại diện tối đa 2 MB.');

    expect(Storage::disk('uploads')->allFiles())->toBe($kept);
    expect(vvT36Profile($t)->avatar_path)->toBe(basename($kept[0]));
});

test('QA AC3: PNG co kenh alpha duoc nhan va ma hoa lai WebP vuong; WebP dong -> 422 (khong 500), khong luu file', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    $im = imagecreatetruecolor(300, 200);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, 150, 100, 120, 120, imagecolorallocatealpha($im, 255, 0, 0, 0));
    $png = vvT36TmpFile((function () use ($im) {
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    })());
    vvT36Upload(VV_QAT36_ME.'/avatar', new UploadedFile($png, 'alpha.png', 'image/png', null, true))->assertOk();
    $bytes = Storage::disk('uploads')->get(vvT36Profile($t)->avatar_path);
    expect(substr($bytes, 8, 4))->toBe('WEBP')->and(getimagesizefromstring($bytes)[0])->toBe(200);

    $files = Storage::disk('uploads')->allFiles();
    app('cache')->store((string) config('cache.limiter'))->flush();
    $r = vvT36Upload(VV_QAT36_ME.'/avatar', vvQaT36AnimatedWebp());
    expect($r->status())->toBeIn([422, 200])->and($r->status())->not->toBe(500);
    if ($r->status() === 422) {
        expect($r->json('errors'))->toHaveKey('avatar');
        expect(Storage::disk('uploads')->allFiles())->toBe($files);
    }
});

// ---------------------------------------------------------------- Hai thao tác chéo nhau

test('QA AC7: giao vien rut dong y roi admin sua bio/anh ho -> /courses va /home van null, khong gui mail, dong y lai thi hien noi dung moi', function () {
    vvT36Env();
    Mail::fake();
    $t = User::factory()->teacher()->withPublicProfile(true)->create();
    $course = vvT36PublishedCourse($t);

    vvQaT36LoginAs($t);
    expect(vvQaT36HomeIds())->toBe([$t->id]);
    vvT36Json('DELETE', VV_QAT36_ME.'/consent')->assertOk();

    vvQaT36LoginAs(vvStaffUser('admin'));
    vvT36Json('PATCH', '/admin/teacher-profiles/'.$t->id, ['bio' => 'Bio do admin sua sau khi rut'])->assertOk();
    vvT36Upload('/admin/teacher-profiles/'.$t->id.'/avatar', UploadedFile::fake()->image('n.jpg', 300, 300))->assertOk();

    $pub = vvT36Public('/courses/'.$course->slug)->assertOk();
    expect($pub->json('teachers.0.bio'))->toBeNull()->and($pub->json('teachers.0.avatar_url'))->toBeNull();
    expect($pub->getContent())->not->toContain(vvT36Profile($t)->avatar_path)->not->toContain('Bio do admin');
    expect(vvQaT36HomeIds())->toBe([]);
    Mail::assertNotQueued(TeacherProfileEditedByStaffMail::class);
    Mail::assertNotSent(TeacherProfileEditedByStaffMail::class);

    vvQaT36LoginAs($t);
    vvT36Json('POST', VV_QAT36_ME.'/consent', ['version' => vvQaT36Version()])->assertOk();
    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Bio do admin sua sau khi rut');
    expect(vvQaT36HomeIds())->toBe([$t->id]);
});

test('QA AC7: admin sua bio (co mail) roi giao vien rut dong y ngay -> /courses/{slug} null lan goi ke tiep', function () {
    vvT36Env();
    Mail::fake();
    $t = User::factory()->teacher()->withPublicProfile(true)->create();
    $course = vvT36PublishedCourse($t);

    vvStaffLogin(vvStaffUser('admin'));
    vvT36Json('PATCH', '/admin/teacher-profiles/'.$t->id, ['bio' => 'Noi dung moi cua admin'])->assertOk();
    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Noi dung moi cua admin');
    Mail::assertQueued(TeacherProfileEditedByStaffMail::class, 1);

    vvQaT36LoginAs($t);
    vvT36Json('DELETE', VV_QAT36_ME.'/consent')->assertOk();

    $pub = vvT36Public('/courses/'.$course->slug)->assertOk();
    expect($pub->json('teachers.0.bio'))->toBeNull()->and($pub->json('teachers.0.avatar_url'))->toBeNull();
    expect(vvQaT36HomeIds())->toBe([]);
});

// ---------------------------------------------------------------- Phiên bản đồng ý

test('QA BR4: doi phien ban dong y: cu van hien; POST phien ban cu 409; POST phien ban moi them 1 dong consents; rut thu hoi MOI dong', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    $course = vvT36PublishedCourse($t);
    vvT36Json('PATCH', VV_QAT36_ME, ['bio' => 'Bio QA'])->assertOk();
    $old = vvQaT36Version();
    vvT36Json('POST', VV_QAT36_ME.'/consent', ['version' => $old])->assertOk();

    config(['teacher_profile.consent_version' => '2099-01', 'teacher_profile.consent_text' => 'Cau chu moi']);

    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Bio QA');
    vvT36Json('POST', VV_QAT36_ME.'/consent', ['version' => $old])->assertStatus(409)->assertJsonPath('code', 'CONSENT_VERSION_CHANGED')->assertJsonPath('errors.current_version', '2099-01');
    expect(Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->count())->toBe(1);

    vvT36Json('POST', VV_QAT36_ME.'/consent', ['version' => '2099-01'])->assertOk()->assertJsonPath('consent.version', '2099-01');
    $rows = Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('policy_version')->sort()->values()->all())->toBe([$old, '2099-01']);

    vvT36Json('DELETE', VV_QAT36_ME.'/consent')->assertOk();
    expect(Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->whereNull('revoked_at')->count())->toBe(0);
    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBeNull();
    expect(vvT36Profile($t)->public_consent_version)->toBeNull();
});

// ---------------------------------------------------------------- Cache công khai

test('QA cache: /home/teachers co ETag, If-None-Match -> 304 khong body; doi du lieu -> ETag doi; khong Set-Cookie', function () {
    vvT36Env();
    $t = vvT36EligibleTeacher();

    $first = vvT36Public('/home/teachers')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($first->headers->get('Cache-Control'))->toContain('max-age=60')->toContain('public');
    expect($first->headers->get('Set-Cookie'))->toBeNull();

    app('auth')->forgetGuards();
    $second = test()->getJson(vvApiUrl('/home/teachers'), ['If-None-Match' => $etag]);
    $second->assertStatus(304);
    expect($second->getContent())->toBe('');

    vvT36SetProfile($t, ['public_consent_at' => null, 'public_consent_version' => null]);
    $third = vvT36Public('/home/teachers')->assertOk();
    expect($third->headers->get('ETag'))->not->toBe($etag)->and($third->json('data'))->toBe([]);
});

test('QA /home/teachers: POST/PUT/DELETE -> 405, tham so limit/per_page khong vuot qua 6', function () {
    vvT36Env();
    foreach (range(1, 8) as $i) {
        vvT36EligibleTeacher(order: $i);
    }
    app('auth')->forgetGuards();
    foreach (['post', 'put', 'delete', 'patch'] as $m) {
        test()->{$m.'Json'}(vvApiUrl('/home/teachers'), [])->assertStatus(405);
    }
    foreach (['limit=100', 'per_page=100', 'page=2', 'teacher_id=1'] as $q) {
        expect(vvT36Public('/home/teachers?'.$q)->assertOk()->json('data'))->toHaveCount(6, $q);
    }
});

// ---------------------------------------------------------------- AC4: dữ liệu bẩn có sẵn trong DB vẫn ra như chữ

test('QA AC4: bio/headline co <script> hoac HTML seed thang DB (qua mat validate) ra API cong khai nhu chuoi JSON nguyen van, khong thuc thi', function () {
    vvT36Env();
    $t = vvT36EligibleTeacher();
    $course = Course::query()->join('course_teacher as ct', 'ct.course_id', '=', 'courses.id')->where('ct.user_id', $t->id)->firstOrFail();
    $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>&lt;b&gt;';
    vvT36SetProfile($t, ['bio' => $evil, 'headline' => '<b>x</b>']);

    foreach (['/home/teachers' => 'data.0', '/courses/'.$course->slug => 'teachers.0'] as $path => $key) {
        $res = vvT36Public($path)->assertOk();
        expect($res->json($key.'.bio'))->toBe($evil);
        expect($res->headers->get('Content-Type'))->toContain('application/json')
            ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
});

// ---------------------------------------------------------------- BR2 còn thiếu

test('QA BR2: dong giang, 1 khoa xoa mem va 1 khoa nhap/ngung ban khong duoc tinh vao so khoa va lop', function () {
    vvT36Env();
    $t = vvT36EligibleTeacher(courseAttrs: ['grade_level' => 10]);
    vvT36PublishedCourse($t, ['grade_level' => 10]);
    $deleted = vvT36PublishedCourse($t, ['grade_level' => 9]);
    $deleted->delete();
    $draft = Course::factory()->create(['grade_level' => 8]);
    $unpub = Course::factory()->unpublished()->create(['grade_level' => 7]);
    DB::table('course_teacher')->insert([['course_id' => $draft->id, 'user_id' => $t->id], ['course_id' => $unpub->id, 'user_id' => $t->id]]);

    $row = vvT36Public('/home/teachers')->json('data.0');

    expect($row['courses_count'])->toBe(2)->and($row['grade_levels'])->toBe([10]);
    expect(TeacherEligibility::reasons($t->fresh(), vvT36Profile($t), 2))->toBe([]);
});

test('QA BR2: giao vien chi con khoa xoa mem / khoa nhap -> khong hien; co khoa published lai thi hien lai khong can bat lai', function () {
    vvT36Env();
    $t = User::factory()->teacher()->withPublicProfile(true)->create();
    $soft = vvT36PublishedCourse($t);
    $soft->delete();
    $draft = Course::factory()->create();
    DB::table('course_teacher')->insert(['course_id' => $draft->id, 'user_id' => $t->id]);
    expect(vvQaT36HomeIds())->toBe([]);

    $soft->restore();
    expect(vvQaT36HomeIds())->toBe([$t->id]);
});

test('QA BR9: tai khoan an danh hoa, bi khoa hoac doi vai tro -> khong hien o /home/teachers, /courses/{slug} van null neu doi vai tro', function () {
    vvT36Env();
    $a = vvT36EligibleTeacher();
    $b = vvT36EligibleTeacher();
    $c = vvT36EligibleTeacher();
    $ok = vvT36EligibleTeacher();
    expect(vvQaT36HomeIds())->toHaveCount(4);
    $cSlug = (string) DB::table('course_teacher as ct')->join('courses', 'courses.id', '=', 'ct.course_id')->where('ct.user_id', $c->id)->value('slug');

    vvT36SetUser($a, ['anonymized_at' => now()]);
    vvT36Lock($b);
    vvT36SetUser($c, ['role' => 'quan_ly_trang']);

    expect(vvQaT36HomeIds())->toBe([$ok->id]);
    expect(TeacherEligibility::reasons($a->fresh(), vvT36Profile($a), 1))->toContain('account_locked');
    expect(TeacherEligibility::reasons($b->fresh(), vvT36Profile($b), 1))->toContain('account_locked');
    expect(TeacherEligibility::reasons($c->fresh(), vvT36Profile($c), 1))->toContain('not_teacher');

    // Người đã đổi vai trò vẫn còn đồng ý trong DB nhưng không lộ ảnh/bio ở chi tiết khóa.
    $pub = vvT36Public('/courses/'.$cSlug)->assertOk();
    expect($pub->getContent())->not->toContain((string) vvT36Profile($c)->avatar_path)->not->toContain((string) vvT36Profile($c)->bio);
});

// ---------------------------------------------------------------- Chặn oan: PlainText ở API admin thật

dataset('vvQaT36Accepted', function () {
    $nfd = fn (string $s) => Normalizer::normalize($s, Normalizer::FORM_D);

    return [
        'NFD tieng Viet' => [$nfd('Tiếng Việt nâng cao: phương trình bậc hai, ước lượng, hợp chất hữu cơ')],
        'NFD chu hoa' => [$nfd('TOÁN HỌC NÂNG CAO – ĐẶNG VĂN ƯỚC')],
        'NFD moi nguyen am x thanh (1)' => [$nfd(collect(mb_str_split('aăâeêioôơuưy'))->flatMap(fn ($c) => array_map(fn ($t) => Normalizer::normalize($c.$t, Normalizer::FORM_C), ['', "\u{0300}", "\u{0301}", "\u{0303}", "\u{0309}", "\u{0323}"]))->implode(''))],
        'ZWJ gia dinh' => ["Toán \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466} vui"],
        'ZWJ co cau vong / tim lua / hai tac' => ["\u{1F3F3}\u{FE0F}\u{200D}\u{1F308} \u{2764}\u{FE0F}\u{200D}\u{1F525} \u{1F3F4}\u{200D}\u{2620}\u{FE0F}"],
        'ZWJ + tong da (co giao)' => ["Cô giáo \u{1F469}\u{1F3FD}\u{200D}\u{1F3EB} \u{1F9D1}\u{1F3FB}\u{200D}\u{1F4BB}"],
        'tong da don' => ["Giỏi \u{1F44D}\u{1F3FF} \u{1F44B}\u{1F3FB} \u{1F918}\u{1F3FD}"],
        'ZWJ gioi tinh' => ["\u{1F926}\u{1F3FD}\u{200D}\u{2640}\u{FE0F} \u{1F3C3}\u{200D}\u{2642}\u{FE0F}"],
        'keycap' => ["Bài 1\u{FE0F}\u{20E3} 2\u{FE0F}\u{20E3} #\u{FE0F}\u{20E3} *\u{FE0F}\u{20E3} 0\u{FE0F}\u{20E3} \u{1F51F}"],
        'co quoc gia' => ["\u{1F1FB}\u{1F1F3} Việt Nam"],
        'NFD + emoji tron' => [$nfd('Ôn tập ước lượng ')."\u{1F4DA}\u{1F469}\u{1F3FD}\u{200D}\u{1F3EB} 1\u{FE0F}\u{20E3}"],
        'ky hieu toan' => ['Toán (a² + b²) – 50% “trích” ‘x’ … — 3×4 ≤ 12 π'],
    ];
});

dataset('vvQaT36Rejected', [
    'ZWJ le' => ["a\u{200D}b"],
    'LRM' => ["a\u{200E}b"],
    'tag ASCII an' => ["a\u{E0041}b"],
    'Hangul filler' => ["a\u{3164}b"],
    'Zalgo 4 dau' => ["a\u{0301}\u{0302}\u{0303}\u{0304}"],
    'the HTML' => ['<b>x</b>'],
    'dau nho hon le' => ['Toán lớp > 9'],
    'ky tu dieu khien' => ["a\u{0007}b"],
]);

function vvQaT36AdminCourse(): array
{
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);
    $course = Course::factory()->create(['created_by' => $admin->id]);
    $chapterId = (int) vvT36Json('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'Chuong goc'])->assertCreated()->json('id');

    return [$course, $chapterId];
}

test('QA chan oan: ten khoa, mo ta ngan, chuong, bai, ten ma giam gia nhan tieng Viet NFD / emoji ZWJ / tong da / keycap', function (string $text) {
    vvT36Env();
    [$course, $chapterId] = vvQaT36AdminCourse();

    vvT36Json('PUT', "/admin/courses/{$course->id}", ['title' => $text])->assertOk()->assertJsonPath('title', $text);
    vvT36Json('PUT', "/admin/courses/{$course->id}", ['short_description' => $text])->assertOk();
    vvT36Json('POST', "/admin/courses/{$course->id}/chapters", ['title' => $text])->assertCreated()->assertJsonPath('title', $text);
    vvT36Json('POST', "/admin/courses/{$course->id}/chapters/{$chapterId}/lessons", ['title' => $text])->assertCreated()->assertJsonPath('title', $text);
    vvT36Json('POST', '/admin/coupons', ['code' => 'QAT36'.strtoupper(bin2hex(random_bytes(3))), 'name' => $text, 'discount_type' => 'percent', 'discount_value' => 5, 'max_uses' => 1])
        ->assertCreated()->assertJsonPath('name', $text);
})->with('vvQaT36Accepted');

test('QA chan oan: van chan dung cac ky tu an/dieu khien o cung cac endpoint (tieu de, chuong, bai, ten ma)', function (string $text) {
    vvT36Env();
    [$course, $chapterId] = vvQaT36AdminCourse();

    vvT36Json('PUT', "/admin/courses/{$course->id}", ['title' => $text])->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvT36Json('POST', "/admin/courses/{$course->id}/chapters", ['title' => $text])->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvT36Json('POST', "/admin/courses/{$course->id}/chapters/{$chapterId}/lessons", ['title' => $text])->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvT36Json('POST', '/admin/coupons', ['code' => 'QAT36BAD1', 'name' => $text, 'discount_type' => 'percent', 'discount_value' => 5, 'max_uses' => 1])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);
})->with('vvQaT36Rejected');

test('QA chan oan: headline/bio cua giao vien nhan NFD + emoji ghep va hien dung nguyen van o API cong khai', function (string $text) {
    vvT36Env();
    $t = vvT36Login('teacher');
    $course = vvT36PublishedCourse($t);
    $short = mb_strlen($text) <= 120 ? $text : 'Toán';

    vvT36Json('PATCH', VV_QAT36_ME, ['headline' => $short, 'bio' => $text."\n".$text])->assertOk();
    vvT36Json('POST', VV_QAT36_ME.'/consent', ['version' => vvQaT36Version()])->assertOk();

    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe($text."\n".$text);
})->with('vvQaT36Accepted');

test('QA BUG-1: co phu cua Anh/Scotland/Wales (emoji chuoi tag U+E0067..U+E007F sau U+1F3F4) bi PlainText chan', function () {
    vvT36Env();
    [$course] = vvQaT36AdminCourse();
    $england = "Học \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

    vvT36Json('PUT', "/admin/courses/{$course->id}", ['title' => $england])->assertOk();
});

test('BUG-1 am: tag dung le, chuoi tag khong ket thuc bang U+E007F, tag sau ky tu khac U+1F3F4 van bi chan o tieu de khoa', function (string $bad) {
    vvT36Env();
    [$course] = vvQaT36AdminCourse();

    vvT36Json('PUT', "/admin/courses/{$course->id}", ['title' => $bad])->assertStatus(422);
})->with([
    'tag le' => ["Học \u{E0067}\u{E0062}"],
    'tag ket thuc le' => ["Học \u{E007F}"],
    'thieu ky tu ket thuc' => ["Học \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}"],
    'sau chu thuong' => ["Học a\u{E0067}\u{E007F}"],
    'sau emoji khac' => ["Học \u{1F600}\u{E0067}\u{E007F}"],
    'tag them le sau co' => ["Học \u{1F3F4}\u{E0067}\u{E007F}\u{E0041}"],
    'tag ngoai khoang E0020-E007E' => ["Học \u{1F3F4}\u{E0001}\u{E007F}"],
]);
