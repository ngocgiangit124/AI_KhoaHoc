<?php

use App\Enums\SubjectStatus;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Subjects\SubjectService;
use App\Support\Like;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../T28/helpers.php';

/** Đăng nhập quản trị thật (staff.idle/MFA/password_fresh đều chạy) rồi trả user. */
function vvSubjectActor(string $state = 'admin'): User
{
    $user = vvStaffUser($state);
    vvStaffLogin($user);

    return $user;
}

function vvAdminSend(string $method, string $path, array $data = []): TestResponse
{
    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

function vvAttach(Subject $subject, ?Course $course = null): Course
{
    $course ??= Course::factory()->create();
    DB::table('course_subject')->insert(['course_id' => $course->id, 'subject_id' => $subject->id]);

    return $course;
}

test('chua dang nhap: 401; hoc sinh: bi chan', function () {
    vvAdminGet('/admin/subjects')->assertUnauthorized();

    $student = User::factory()->student()->create();
    test()->actingAs($student);
    vvAdminGet('/admin/subjects')->assertStatus(403);
});

test('AC1: admin va quan ly trang tao chuyen de, mac dinh active, sinh slug, ghi audit', function (string $state) {
    $actor = vvSubjectActor($state);

    $response = vvAdminSend('POST', '/admin/subjects', ['name' => '  Đại   số  '])
        ->assertCreated()
        ->assertJsonPath('name', 'Đại số')
        ->assertJsonPath('slug', 'dai-so')
        ->assertJsonPath('status', 'active');

    $subject = Subject::query()->findOrFail($response->json('id'));
    expect($subject->status)->toBe(SubjectStatus::Active);

    $log = AuditLog::query()->where('action', 'subject.create')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id)
        ->and($log->subject_type)->toBe($subject->getMorphClass())
        ->and($log->subject_id)->toBe($subject->id);
})->with(['admin', 'pageManager']);

test('AC2/BR1: ten trung (khong phan biet hoa thuong va dau) -> 422 va khong tao them', function (string $dup) {
    Subject::factory()->create(['name' => 'Hình học', 'slug' => 'hinh-hoc']);
    vvSubjectActor();

    vvAdminSend('POST', '/admin/subjects', ['name' => $dup])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'Chuyên đề đã tồn tại.');

    expect(Subject::query()->count())->toBe(1);
})->with(['Hình học', 'HÌNH HỌC', 'hinh hoc']);

test('slug trung thi them hau to', function () {
    Subject::factory()->create(['name' => 'Đại số', 'slug' => 'dai-so']);
    vvSubjectActor();

    vvAdminSend('POST', '/admin/subjects', ['name' => 'Dai-so'])
        ->assertCreated()->assertJsonPath('slug', 'dai-so-2');
});

test('ten rong, toan khoang trang, qua dai, co HTML -> 422', function (mixed $name) {
    vvSubjectActor();

    vvAdminSend('POST', '/admin/subjects', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
    expect(Subject::query()->count())->toBe(0);
})->with([
    'rong' => '',
    'khoang trang' => '   ',
    'null' => null,
    'dai' => str_repeat('a', 101),
    'script' => '<script>alert(1)</script>',
    'the b' => 'Toán <b>hay</b>',
    'dau lon' => 'a > b',
    'mang' => [['x']],
]);

test('ten co ky tu dac biet khong phai HTML van hop le va tra ve nguyen van (frontend escape)', function () {
    vvSubjectActor();

    vvAdminSend('POST', '/admin/subjects', ['name' => 'Ôn thi 10 & THPT "QG"'])
        ->assertCreated()->assertJsonPath('name', 'Ôn thi 10 & THPT "QG"');
});

test('BR4: giao vien chi xem active; tao/sua/doi trang thai/xoa -> 403 (ke ca payload sai)', function () {
    $active = Subject::factory()->create(['name' => 'Số học']);
    Subject::factory()->hidden()->create(['name' => 'Ẩn']);
    vvSubjectActor('teacher');

    vvAdminGet('/admin/subjects?status=hidden')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id)
        ->assertJsonMissingPath('data.0.courses_count');

    vvAdminGet('/admin/subjects?all=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('meta');

    vvAdminSend('POST', '/admin/subjects', ['name' => 'Mới'])->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    vvAdminSend('POST', '/admin/subjects', [])->assertForbidden();
    vvAdminSend('PUT', "/admin/subjects/{$active->id}", ['name' => 'Đổi'])->assertForbidden();
    vvAdminSend('PATCH', "/admin/subjects/{$active->id}/status", ['status' => 'hidden'])->assertForbidden();
    vvAdminSend('DELETE', "/admin/subjects/{$active->id}")->assertForbidden();

    expect($active->fresh()->name)->toBe('Số học')->and(Subject::query()->count())->toBe(2);
});

test('staff: danh sach co phan trang 25, loc status/q, courses_count', function () {
    Subject::factory()->count(30)->create();
    $math = Subject::factory()->create(['name' => 'Toán 100%_x']);
    Subject::factory()->hidden()->create(['name' => 'Ẩn thử']);
    vvAttach($math);
    vvAttach($math);
    vvSubjectActor('pageManager');

    vvAdminGet('/admin/subjects')->assertOk()
        ->assertJsonCount(25, 'data')->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 32);

    vvAdminGet('/admin/subjects?status=hidden')->assertOk()->assertJsonCount(1, 'data');

    // `%` và `_` được escape: chỉ khớp đúng chuỗi, không phải ký tự đại diện.
    vvAdminGet('/admin/subjects?q='.urlencode('100%_'))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.courses_count', 2);
    vvAdminGet('/admin/subjects?q='.urlencode('%'))->assertOk()->assertJsonCount(1, 'data');

    vvAdminGet('/admin/subjects?per_page=999')->assertStatus(422);
});

test('AC5: doi ten giu slug va khoa hoc da gan thay ten moi; ten cu cua chinh no khong bi bao trung', function () {
    $subject = Subject::factory()->create(['name' => 'Đại số', 'slug' => 'dai-so']);
    $course = vvAttach($subject);
    vvSubjectActor();

    vvAdminSend('PUT', "/admin/subjects/{$subject->id}", ['name' => 'Đại số'])->assertOk();
    vvAdminSend('PUT', "/admin/subjects/{$subject->id}", ['name' => 'Đại số 9'])
        ->assertOk()->assertJsonPath('name', 'Đại số 9')->assertJsonPath('slug', 'dai-so');

    expect($course->subjects()->first()->name)->toBe('Đại số 9')
        ->and(AuditLog::query()->where('action', 'subject.update')->count())->toBe(1);

    Subject::factory()->create(['name' => 'Số học']);
    vvAdminSend('PUT', "/admin/subjects/{$subject->id}", ['name' => 'số học'])
        ->assertStatus(422)->assertJsonPath('errors.name.0', 'Chuyên đề đã tồn tại.');
});

test('BR3/AC6: an chuyen de khong dong den khoa hoc da gan; hien lai duoc; trang thai sai -> 422', function () {
    $subject = Subject::factory()->create();
    $course = vvAttach($subject);
    vvSubjectActor();

    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", ['status' => 'hidden'])
        ->assertOk()->assertJsonPath('status', 'hidden');
    expect($course->subjects()->count())->toBe(1);

    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", ['status' => 'active'])
        ->assertOk()->assertJsonPath('status', 'active');
    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", ['status' => 'deleted'])->assertStatus(422);
    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", [])->assertStatus(422);
});

test('AC3: dang gan khoa hoc -> 409 SUBJECT_IN_USE, khong xoa (ke ca khoa da xoa mem)', function () {
    $subject = Subject::factory()->create();
    $course = vvAttach($subject);
    $course->delete();
    vvSubjectActor();

    vvAdminSend('DELETE', "/admin/subjects/{$subject->id}")
        ->assertStatus(409)->assertJson(['code' => 'SUBJECT_IN_USE']);

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subject.delete')->count())->toBe(0);
});

test('AC4: chua gan khoa hoc -> xoa 204, ghi audit; xoa lai -> 404', function () {
    $subject = Subject::factory()->create(['name' => 'Tạm']);
    $actor = vvSubjectActor('pageManager');

    vvAdminSend('DELETE', "/admin/subjects/{$subject->id}")->assertNoContent();

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeFalse();
    $log = AuditLog::query()->where('action', 'subject.delete')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id)->and($log->subject_id)->toBe($subject->id)
        ->and($log->changes)->toBe(['name' => 'Tạm']);

    vvAdminSend('DELETE', "/admin/subjects/{$subject->id}")->assertNotFound();
});

test('khoa FK course_subject.subject_id: xoa cung o tang DB cung bi chan', function () {
    $subject = Subject::factory()->create();
    vvAttach($subject);

    expect(fn () => DB::table('subjects')->where('id', $subject->id)->delete())
        ->toThrow(QueryException::class);
});

test('route chuyen de nam trong nhom staff (du middleware staff.*)', function () {
    $user = vvSubjectActor();
    $route = app('router')->getRoutes()->getByName('admin.subjects.store');

    expect($route->gatherMiddleware())->toContain('staff.idle', 'staff.mfa_passed', 'staff.password_fresh', 'admin.origin');
    expect($user->isAdmin())->toBeTrue();
});

test('R4: courses_count dem ca khoa da xoa mem; giao vien voi per_page/status sai khong bi lo gi', function () {
    $subject = Subject::factory()->create();
    vvAttach($subject)->delete();
    vvAttach($subject);
    vvSubjectActor();

    vvAdminGet('/admin/subjects')->assertOk()->assertJsonPath('data.0.courses_count', 2);
});

test('R4: giao vien gui per_page/status sai -> chi 422 validate, khong lo du lieu hidden', function () {
    Subject::factory()->hidden()->create();
    vvSubjectActor('teacher');

    vvAdminGet('/admin/subjects?per_page=7')->assertStatus(422);
    vvAdminGet('/admin/subjects?status=hidden')->assertOk()->assertJsonCount(0, 'data');
});

test('R4: audit subject.status ghi from/to', function () {
    $subject = Subject::factory()->create();
    vvSubjectActor();

    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", ['status' => 'hidden'])->assertOk();
    vvAdminSend('PATCH', "/admin/subjects/{$subject->id}/status", ['status' => 'hidden'])->assertOk();

    $logs = AuditLog::query()->where('action', 'subject.status')->get();
    expect($logs)->toHaveCount(1)->and($logs[0]->changes)->toEqual(['status' => ['from' => 'active', 'to' => 'hidden']]);
});

test('R4: SubjectService::create bat 1062 ten trung (bo qua Request) -> ValidationException errors.name', function () {
    Subject::factory()->create(['name' => 'Hình học']);

    try {
        app(SubjectService::class)->create('hinh hoc');
        $this->fail('phải ném ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('name');
    }
    expect(Subject::query()->count())->toBe(1);
});

test('R2: va cham slug (ten khac nhau, slug trung do race) duoc retry hau to, khong bao loi ten', function () {
    // Mô phỏng race: slug đã bị chiếm giữa lúc sinh và lúc insert — sinh slug trả về 'c' cố định lần đầu.
    Subject::factory()->create(['name' => 'C++', 'slug' => 'c']);
    $service = new class(app(AuditLogger::class)) extends SubjectService
    {
        public int $calls = 0;

        protected function uniqueSlug(string $name): string
        {
            return ++$this->calls === 1 ? 'c' : parent::uniqueSlug($name);
        }
    };

    $subject = $service->create('C#');

    expect($subject->slug)->toBe('c-2')->and($service->calls)->toBe(2);
});

test('R4: delete bat FK 1451 (gan dong thoi) -> 409 SUBJECT_IN_USE', function () {
    $subject = Subject::factory()->create();
    vvAttach($subject);

    // Bỏ qua kiểm `exists` bằng service giả để chạm nhánh FK 1451.
    $service = new class(app(AuditLogger::class)) extends SubjectService
    {
        protected function hasCourses(int $subjectId): bool
        {
            return false;
        }
    };

    try {
        $service->delete($subject);
        $this->fail('phải ném DomainException');
    } catch (DomainException $e) {
        expect($e->code())->toBe('SUBJECT_IN_USE')->and($e->status())->toBe(409);
    }
});

// ---- QA bổ sung (T06-T07) ----

test('Like: escape %, _, backslash va contains/startsWith', function () {
    expect(Like::escape('a%b_c\\d'))->toBe('a\\%b\\_c\\\\d')
        ->and(Like::contains('50%'))->toBe('%50\\%%')
        ->and(Like::startsWith('x_'))->toBe('x\\_%');
});

test('q: "_" va "\\" la ky tu thuong, khong khop moi ky tu', function () {
    Subject::factory()->create(['name' => 'Toan A']);
    Subject::factory()->create(['name' => 'Toan_B']);
    Subject::factory()->create(['name' => 'Toan\\C']);
    vvSubjectActor();

    vvAdminGet('/admin/subjects?q='.urlencode('n_'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Toan_B');
    vvAdminGet('/admin/subjects?q='.urlencode('\\'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Toan\\C');
    vvAdminGet('/admin/subjects?q='.urlencode('toan'))->assertOk()->assertJsonCount(3, 'data');
    vvAdminGet('/admin/subjects?q='.urlencode(str_repeat('a', 101)))->assertStatus(422);
});

test('q khong phan biet hoa/thuong va dau (collation)', function () {
    Subject::factory()->create(['name' => 'Hình học']);
    vvSubjectActor();

    vvAdminGet('/admin/subjects?q='.urlencode('HINH'))->assertOk()->assertJsonCount(1, 'data');
});

test('phan trang: per_page 50 hop le, trang 2, per_page khong hop le -> 422, all=1 khong meta', function () {
    Subject::factory()->count(60)->create();
    vvSubjectActor();

    vvAdminGet('/admin/subjects?per_page=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.last_page', 2);
    vvAdminGet('/admin/subjects?page=3')->assertOk()->assertJsonCount(10, 'data');
    vvAdminGet('/admin/subjects?page=4')->assertOk()->assertJsonCount(0, 'data');
    vvAdminGet('/admin/subjects?page=2')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.current_page', 2);
    foreach (['per_page=0', 'per_page=-1', 'per_page=abc', 'per_page=24', 'status=banana'] as $bad) {
        vvAdminGet('/admin/subjects?'.$bad)->assertStatus(422);
    }
    vvAdminGet('/admin/subjects?all=1')->assertOk()->assertJsonCount(60, 'data')->assertJsonMissingPath('meta')->assertJsonMissingPath('links');
});

test('danh sach sap theo ten roi id, khong N+1 (so query khong doi theo so chuyen de)', function () {
    Subject::factory()->count(3)->create();
    vvSubjectActor();
    DB::enableQueryLog();
    vvAdminGet('/admin/subjects')->assertOk();
    DB::flushQueryLog();
    vvAdminGet('/admin/subjects')->assertOk();
    $few = count(DB::getQueryLog());

    Subject::factory()->count(20)->create();
    DB::flushQueryLog();
    vvAdminGet('/admin/subjects')->assertOk();
    expect(count(DB::getQueryLog()))->toBe($few);
});

test('GV: all=1 va q chi tra active; id hidden khong lo qua q', function () {
    Subject::factory()->create(['name' => 'Hình học']);
    Subject::factory()->hidden()->create(['name' => 'Hình bí mật']);
    vvSubjectActor('teacher');

    vvAdminGet('/admin/subjects?all=1&q=Hình')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Hình học');
});

test('quan ly trang: sua, doi trang thai, xoa deu duoc (BR4)', function () {
    $s = Subject::factory()->create(['name' => 'Cũ']);
    vvSubjectActor('pageManager');

    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'Mới'])->assertOk()->assertJsonPath('name', 'Mới');
    vvAdminSend('PATCH', "/admin/subjects/{$s->id}/status", ['status' => 'hidden'])->assertOk()->assertJsonPath('status', 'hidden');
    vvAdminSend('DELETE', "/admin/subjects/{$s->id}")->assertNoContent();
});

test('hoc sinh (da dang nhap) bi chan o ca 5 route; chua dang nhap 401 o ghi', function () {
    $s = Subject::factory()->create();
    $student = User::factory()->student()->create();
    test()->actingAs($student);

    vvAdminSend('POST', '/admin/subjects', ['name' => 'X'])->assertStatus(403);
    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'X'])->assertStatus(403);
    vvAdminSend('PATCH', "/admin/subjects/{$s->id}/status", ['status' => 'hidden'])->assertStatus(403);
    vvAdminSend('DELETE', "/admin/subjects/{$s->id}")->assertStatus(403);
    expect(Subject::query()->count())->toBe(1)->and($s->fresh()->status->value)->toBe('active');
});

test('chua dang nhap: ghi -> 401 (khong tao)', function () {
    $s = Subject::factory()->create();
    vvAdminSend('POST', '/admin/subjects', ['name' => 'X'])->assertUnauthorized();
    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'X'])->assertUnauthorized();
    vvAdminSend('PATCH', "/admin/subjects/{$s->id}/status", ['status' => 'hidden'])->assertUnauthorized();
    vvAdminSend('DELETE', "/admin/subjects/{$s->id}")->assertUnauthorized();
    expect(Subject::query()->count())->toBe(1);
});

test('doi ten thanh ten trung chuyen de KHAC -> 422; doi hoa/thuong chinh no -> ok; id khong ton tai -> 404', function () {
    Subject::factory()->create(['name' => 'Hình học']);
    $s = Subject::factory()->create(['name' => 'Đại số']);
    vvSubjectActor();

    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'HINH HOC'])->assertStatus(422)->assertJsonPath('errors.name.0', 'Chuyên đề đã tồn tại.');
    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'ĐẠI SỐ'])->assertOk()->assertJsonPath('name', 'ĐẠI SỐ');
    vvAdminSend('PUT', '/admin/subjects/999999', ['name' => 'Z'])->assertNotFound();
    vvAdminSend('PATCH', '/admin/subjects/999999/status', ['status' => 'hidden'])->assertNotFound();
    vvAdminSend('DELETE', '/admin/subjects/999999')->assertNotFound();
});

test('khong ghi audit khi sua khong doi ten / khong doi trang thai', function () {
    $s = Subject::factory()->create(['name' => 'Số học']);
    vvSubjectActor();

    vvAdminSend('PUT', "/admin/subjects/{$s->id}", ['name' => 'Số học'])->assertOk();
    vvAdminSend('PATCH', "/admin/subjects/{$s->id}/status", ['status' => 'active'])->assertOk();

    expect(AuditLog::query()->whereIn('action', ['subject.update', 'subject.status'])->count())->toBe(0);
});

test('bien ten: dung 100 ky tu hop le, 101 loi; tieng Viet co dau 100 ky tu; emoji; NFD trung NFC', function () {
    vvSubjectActor();

    vvAdminSend('POST', '/admin/subjects', ['name' => str_repeat('ế', 100)])->assertCreated();
    vvAdminSend('POST', '/admin/subjects', ['name' => str_repeat('ế', 101)])->assertStatus(422);
    vvAdminSend('POST', '/admin/subjects', ['name' => 'Toán 🧮'])->assertCreated()->assertJsonPath('name', 'Toán 🧮');

    // "Hình" dạng tổ hợp (NFD) phải bị coi là trùng với dạng dựng sẵn (NFC).
    vvAdminSend('POST', '/admin/subjects', ['name' => 'Hình học'])->assertCreated();
    $nfd = Normalizer::normalize('Hình học', Normalizer::FORM_D);
    vvAdminSend('POST', '/admin/subjects', ['name' => $nfd])->assertStatus(422);

    vvAdminSend('POST', '/admin/subjects', ['name' => "Toán\u{0000}x"])->assertStatus(422);
    vvAdminSend('POST', '/admin/subjects', ['name' => "Toán\tx\n y"])->assertCreated()->assertJsonPath('name', 'Toán x y');
    vvAdminSend('POST', '/admin/subjects', ['name' => ['a']])->assertStatus(422);
});

test('mass assignment: slug/status/id gui kem khi tao/sua bi bo qua', function () {
    vvSubjectActor();

    $r = vvAdminSend('POST', '/admin/subjects', ['name' => 'Tự do', 'slug' => 'hack', 'status' => 'hidden', 'id' => 777])->assertCreated();
    expect($r->json('slug'))->toBe('tu-do')->and($r->json('status'))->toBe('active')->and($r->json('id'))->not->toBe(777);

    vvAdminSend('PUT', '/admin/subjects/'.$r->json('id'), ['name' => 'Tự do 2', 'slug' => 'hack', 'status' => 'hidden'])
        ->assertOk()->assertJsonPath('slug', 'tu-do')->assertJsonPath('status', 'active');
});

test('response khong lo cot ngoai hop dong', function () {
    Subject::factory()->create();
    vvSubjectActor();

    $keys = array_keys(vvAdminGet('/admin/subjects')->json('data.0'));
    expect($keys)->toEqualCanonicalizing(['id', 'name', 'slug', 'status', 'courses_count', 'created_at', 'updated_at']);
});
