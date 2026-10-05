<?php

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use App\Support\Mask;
use Illuminate\Support\Facades\DB;

function vvEnrollSvc(): EnrollmentService
{
    return app(EnrollmentService::class);
}

function vvFreeCourse(): Course
{
    return Course::factory()->published()->create();
}

function vvDomainCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (DomainException $e) {
        return $e->code().':'.$e->status();
    }

    return null;
}

test('requestFree tao pending_approval, source free_approval, requested_at, audit', function () {
    $student = User::factory()->verified()->create();
    $course = vvFreeCourse();

    $e = vvEnrollSvc()->requestFree($student, $course);

    expect($e->status)->toBe(EnrollmentStatus::PendingApproval)
        ->and($e->source)->toBe(EnrollmentSource::FreeApproval)
        ->and($e->requested_at)->not->toBeNull()
        ->and($course->fresh()->enrollments_count)->toBe(0);
    expect(AuditLog::where('action', 'enrollment.request')->where('subject_id', $e->id)->exists())->toBeTrue();
});

test('requestFree: khoa co phi 422 COURSE_NOT_FREE; khoa chua published/khong ton tai 404', function () {
    $student = User::factory()->verified()->create();

    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, Course::factory()->published()->paid()->create())))->toBe('COURSE_NOT_FREE:422')
        ->and(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, Course::factory()->create())))->toBe('NOT_FOUND:404')
        ->and(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, Course::factory()->unpublished()->create())))->toBe('NOT_FOUND:404');

    $deleted = vvFreeCourse();
    $deleted->delete();
    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $deleted)))->toBe('NOT_FOUND:404');
});

test('AC4: dang pending -> 409 ENROLLMENT_PENDING; da active -> 409 ALREADY_OWNED', function () {
    $student = User::factory()->verified()->create();
    $course = vvFreeCourse();
    vvEnrollSvc()->requestFree($student, $course);

    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $course)))->toBe('ENROLLMENT_PENDING:409');

    $other = vvFreeCourse();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $other->id]);
    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $other)))->toBe('ALREADY_OWNED:409');
    expect(Enrollment::where('user_id', $student->id)->count())->toBe(2);
});

test('approve: active, approved_by/at, activated_at, enrollments_count +1, audit; lan 2 -> ALREADY_PROCESSED', function () {
    $student = User::factory()->verified()->create();
    $approver = User::factory()->admin()->create();
    $course = vvFreeCourse();
    $e = vvEnrollSvc()->requestFree($student, $course);

    vvEnrollSvc()->approve($e, $approver);

    $fresh = $e->fresh();
    expect($fresh->status)->toBe(EnrollmentStatus::Active)
        ->and($fresh->approved_by)->toBe($approver->id)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->activated_at)->not->toBeNull()
        ->and($course->fresh()->enrollments_count)->toBe(1);
    expect(AuditLog::where('action', 'enrollment.approve')->where('subject_id', $e->id)->exists())->toBeTrue();

    expect(vvDomainCode(fn () => vvEnrollSvc()->approve(Enrollment::find($e->id), $approver)))->toBe('ALREADY_PROCESSED:409')
        ->and(vvDomainCode(fn () => vvEnrollSvc()->reject(Enrollment::find($e->id), $approver)))->toBe('ALREADY_PROCESSED:409')
        ->and($course->fresh()->enrollments_count)->toBe(1);
});

test('reject (AC3/AC5): rejected + ly do, count khong doi, duoc gui lai yeu cau moi, giu lich su', function () {
    $student = User::factory()->verified()->create();
    $approver = User::factory()->pageManager()->create();
    $course = vvFreeCourse();
    $e = vvEnrollSvc()->requestFree($student, $course);

    vvEnrollSvc()->reject($e, $approver, 'Chua du dieu kien');

    expect($e->fresh()->status)->toBe(EnrollmentStatus::Rejected)
        ->and($e->fresh()->rejection_reason)->toBe('Chua du dieu kien')
        ->and($course->fresh()->enrollments_count)->toBe(0);

    $again = vvEnrollSvc()->requestFree($student, $course);
    expect($again->id)->not->toBe($e->id)
        ->and($again->status)->toBe(EnrollmentStatus::PendingApproval)
        ->and(Enrollment::where('user_id', $student->id)->count())->toBe(2);
});

test('approve khoa da xoa mem -> 409 COURSE_UNAVAILABLE, van pending, count khong doi; van tu choi duoc; reject khong ghi approved_*', function () {
    $student = User::factory()->verified()->create();
    $admin = User::factory()->admin()->create();
    $course = vvFreeCourse();
    $e = vvEnrollSvc()->requestFree($student, $course);
    Course::whereKey($course->id)->first()->delete();

    expect(vvDomainCode(fn () => vvEnrollSvc()->approve($e, $admin)))->toBe('COURSE_UNAVAILABLE:409');
    expect($e->fresh()->status)->toBe(EnrollmentStatus::PendingApproval)
        ->and(Course::withTrashed()->find($course->id)->enrollments_count)->toBe(0);

    vvEnrollSvc()->reject($e, $admin, 'x');
    $f = $e->fresh();
    expect($f->status)->toBe(EnrollmentStatus::Rejected)->and($f->approved_by)->toBeNull()->and($f->approved_at)->toBeNull();
    expect(AuditLog::where('action', 'enrollment.reject')->where('subject_id', $e->id)->first()->actor_id)->toBeNull();
});

test('revoke: active -> revoked, count -1 (khong am), cho phep mua/xin lai; lan 2 409', function () {
    $student = User::factory()->verified()->create();
    $course = vvFreeCourse();
    $e = Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    Course::whereKey($course->id)->update(['enrollments_count' => 1]);

    vvEnrollSvc()->revoke($e, 'refund');

    expect($e->fresh()->status)->toBe(EnrollmentStatus::Revoked)
        ->and($e->fresh()->revoked_reason)->toBe('refund')
        ->and($e->fresh()->revoked_at)->not->toBeNull()
        ->and($course->fresh()->enrollments_count)->toBe(0);
    expect(vvDomainCode(fn () => vvEnrollSvc()->revoke(Enrollment::find($e->id), 'refund')))->toBe('ALREADY_PROCESSED:409');

    // Count không xuống âm kể cả khi lệch.
    $e2 = Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    vvEnrollSvc()->revoke($e2, str_repeat('x', 80));
    expect($course->fresh()->enrollments_count)->toBe(0)
        ->and(strlen((string) $e2->fresh()->revoked_reason))->toBe(50);
});

test('revoke chi cho active: pending -> 409', function () {
    $e = Enrollment::factory()->pendingApproval()->create();

    expect(vvDomainCode(fn () => vvEnrollSvc()->revoke($e, 'admin')))->toBe('ALREADY_PROCESSED:409');
});

test('grantPurchase: tao active/purchase kem order_id, count +1; goi lai idempotent (IPN trung)', function () {
    $student = User::factory()->verified()->create();
    $course = Course::factory()->published()->paid()->create();

    $a = vvEnrollSvc()->grantPurchase($student, $course, 77);
    $b = vvEnrollSvc()->grantPurchase($student, $course, 77);

    expect($a->wasRecentlyCreated)->toBeTrue()
        ->and($a->status)->toBe(EnrollmentStatus::Active)
        ->and($a->source)->toBe(EnrollmentSource::Purchase)
        ->and($a->fresh()->order_id)->toBe(77)
        ->and($b->id)->toBe($a->id)
        ->and($b->wasRecentlyCreated)->toBeFalse()
        ->and(Enrollment::where('user_id', $student->id)->count())->toBe(1)
        ->and($course->fresh()->enrollments_count)->toBe(1);
});

test('grantPurchase: dang pending (xin mien phi truoc khi doi gia) -> nang thanh active/purchase, khong tao dong moi', function () {
    $student = User::factory()->verified()->create();
    $course = vvFreeCourse();
    $pending = vvEnrollSvc()->requestFree($student, $course);

    $g = vvEnrollSvc()->grantPurchase($student, $course, 5);

    expect($g->id)->toBe($pending->id)
        ->and($g->fresh()->status)->toBe(EnrollmentStatus::Active)
        ->and($g->fresh()->source)->toBe(EnrollmentSource::Purchase)
        ->and($course->fresh()->enrollments_count)->toBe(1);
});

test('grantPurchase sau khi revoked/rejected tao dong moi (giu lich su)', function () {
    $student = User::factory()->verified()->create();
    $course = Course::factory()->published()->paid()->create();
    $old = Enrollment::factory()->revoked()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $g = vvEnrollSvc()->grantPurchase($student, $course, 9);

    expect($g->id)->not->toBe($old->id)->and(Enrollment::where('user_id', $student->id)->count())->toBe(2);
});

test('grantPurchase ben trong DB::transaction ngoai: 1062 van dung (dong thang cuoc duoc dung lai), count khong lech', function () {
    $student = User::factory()->verified()->create();
    $course = Course::factory()->published()->paid()->create();

    DB::transaction(function () use ($student, $course) {
        $a = vvEnrollSvc()->grantPurchase($student, $course, 11);
        $b = vvEnrollSvc()->grantPurchase($student, $course, 11);

        expect($b->id)->toBe($a->id)->and($b->order_id)->toBe(11);
    });

    expect(Enrollment::where('user_id', $student->id)->count())->toBe(1)
        ->and($course->fresh()->enrollments_count)->toBe(1);
});

test('approve khi khoa da chuyen sang co phi -> 422 COURSE_NOT_FREE, van pending, count khong doi', function () {
    $student = User::factory()->verified()->create();
    $approver = User::factory()->admin()->create();
    $course = vvFreeCourse();
    $e = vvEnrollSvc()->requestFree($student, $course);
    Course::whereKey($course->id)->update(['price' => 199000]);

    expect(vvDomainCode(fn () => vvEnrollSvc()->approve($e, $approver)))->toBe('COURSE_NOT_FREE:422');
    expect($e->fresh()->status)->toBe(EnrollmentStatus::PendingApproval)
        ->and($e->fresh()->approved_by)->toBeNull()
        ->and($course->fresh()->enrollments_count)->toBe(0);

    // Vẫn từ chối được.
    vvEnrollSvc()->reject($e, $approver, 'Khoa da co phi');
    expect($e->fresh()->status)->toBe(EnrollmentStatus::Rejected);
});

test('khoa xoa mem: requestFree -> 404 NOT_FOUND (ca model nap truoc khi xoa), grantPurchase -> COURSE_UNAVAILABLE, khong tao dong / count', function () {
    $student = User::factory()->verified()->create();
    $free = vvFreeCourse();
    $stale = Course::find($free->id); // model "cu" nạp trước khi khóa bị xoá (mô phỏng xoá xen giữa)
    Course::whereKey($free->id)->first()->delete();

    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $stale)))->toBe('NOT_FOUND:404');

    $paid = Course::factory()->published()->paid()->create();
    $stalePaid = Course::find($paid->id);
    $paid->delete();

    expect(vvDomainCode(fn () => vvEnrollSvc()->grantPurchase($student, $stalePaid, 5)))->toBe('COURSE_UNAVAILABLE:409');
    expect(Enrollment::where('user_id', $student->id)->count())->toBe(0)
        ->and(Course::withTrashed()->find($paid->id)->enrollments_count)->toBe(0);
});

test('requestFree dung model cu khi khoa vua chuyen unpublished/co phi -> kiem lai tren dong da khoa', function () {
    $student = User::factory()->verified()->create();
    $a = vvFreeCourse();
    $staleA = Course::find($a->id);
    Course::whereKey($a->id)->update(['status' => 'unpublished']);
    $b = vvFreeCourse();
    $staleB = Course::find($b->id);
    Course::whereKey($b->id)->update(['price' => 1000]);

    expect(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $staleA)))->toBe('NOT_FOUND:404')
        ->and(vvDomainCode(fn () => vvEnrollSvc()->requestFree($student, $staleB)))->toBe('COURSE_NOT_FREE:422')
        ->and(Enrollment::count())->toBe(0);
});

test('grantPurchase van cap quyen khi khoa chi ngung ban (unpublished): nguoi da tra tien giu quyen', function () {
    $student = User::factory()->verified()->create();
    $course = Course::factory()->unpublished()->paid()->create();

    expect(vvEnrollSvc()->grantPurchase($student, $course, 3)->status)->toBe(EnrollmentStatus::Active);
});

test('counters:recount sua enrollments_count lech, --dry-run khong sua', function () {
    $course = vvFreeCourse();
    Enrollment::factory()->count(2)->create(['course_id' => $course->id]);
    Enrollment::factory()->revoked()->create(['course_id' => $course->id]);
    Course::whereKey($course->id)->update(['enrollments_count' => 9]);

    $this->artisan('counters:recount --dry-run')->assertSuccessful();
    expect($course->fresh()->enrollments_count)->toBe(9);

    $this->artisan('counters:recount')->assertSuccessful();
    expect($course->fresh()->enrollments_count)->toBe(2);
});

test('Mask email/phone', function () {
    expect(Mask::email('nguyenvanan@gmail.com'))->toBe('n***@gmail.com')
        ->and(Mask::email(null))->toBeNull()
        ->and(Mask::phone('0912345678'))->toBe('******5678')
        ->and(Mask::phone(null))->toBeNull()
        ->and(Mask::phone('1234'))->toBe('****')
        ->and(Mask::phone('12345'))->toBe('*2345');
});
