<?php

use App\Enums\OrderStatus;
use App\Jobs\FinalizeAccountDeletionJob;
use App\Mail\EnrollmentDecisionMail;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Privacy\AccountDeletionFinalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T28/helpers.php';

function vvT34Anonymized(array $attrs = []): User
{
    $u = User::factory()->student()->verified()->create($attrs);
    DB::table('users')->where('id', $u->id)->update(['anonymized_at' => now(), 'name' => 'Tài khoản đã xoá', 'email' => null, 'phone' => null]);

    return $u->fresh();
}

function vvT34Finalize(User $u): void
{
    app(AccountDeletionFinalizer::class)->finalize($u->id);
}

test('(h) don pending khong link -> cancelled / account_deleted, log he thong; khong dung toi don da tra', function () {
    $u = vvT34Anonymized();
    $pending = vvT34PendingOrder($u, withAttempt: false);
    $paid = Order::factory()->paid()->create(['user_id' => $u->id]);
    DB::table('order_items')->insert(['order_id' => $paid->id, 'course_id' => Course::factory()->published()->paid()->create()->id, 'course_title' => 'T', 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);
    DB::table('order_status_logs')->insert(['order_id' => $paid->id, 'from_status' => 'pending', 'to_status' => 'paid', 'actor_type' => 'gateway', 'created_at' => now()]);

    vvT34Finalize($u);

    $pending->refresh();
    expect($pending->status)->toBe(OrderStatus::Cancelled)->and($pending->status_reason)->toBe('account_deleted')->and($pending->cancelled_at)->not->toBeNull();
    $log = DB::table('order_status_logs')->where('order_id', $pending->id)->orderByDesc('id')->first();
    expect($log->to_status)->toBe('cancelled')->and($log->actor_type)->toBe('system')->and($log->actor_id)->toBeNull();

    // (j) đơn đã trả giữ nguyên
    expect($paid->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(DB::table('order_items')->where('order_id', $paid->id)->count())->toBe(1)
        ->and(DB::table('order_status_logs')->where('order_id', $paid->id)->count())->toBe(1);
});

test('(h) don pending co attempt het han / failed -> huy; link song hoac created dang bay -> giu', function (string $kind, bool $cancelled) {
    $u = vvT34Anonymized();
    $order = match ($kind) {
        'pending_het_han' => vvT34PendingOrder($u, 'pending', now()->subMinute()),
        'failed' => vvT34PendingOrder($u, 'failed', now()->addMinutes(20)),
        'error' => vvT34PendingOrder($u, 'error', now()->addMinutes(20)),
        'pending_song' => vvT34PendingOrder($u, 'pending', now()->addMinutes(20)),
        'created_dang_bay' => vvT34PendingOrder($u, 'created', now()->addMinutes(20)),
    };

    vvT34Finalize($u);

    expect($order->fresh()->status === OrderStatus::Cancelled)->toBe($cancelled);
})->with([
    ['pending_het_han', true], ['failed', true], ['error', true], ['pending_song', false], ['created_dang_bay', false],
]);

test('(h) created cu (>60s, request chet) khong con tinh la dang bay', function () {
    $u = vvT34Anonymized();
    $order = vvT34PendingOrder($u, 'created');
    PaymentAttempt::query()->where('order_id', $order->id)->update(['created_at' => now()->subMinutes(5)]);

    vvT34Finalize($u);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('(h) pending_approval -> rejected, KHONG gui mail; active giu nguyen; audit enrollment.withdraw actor null', function () {
    Mail::fake();
    $u = vvT34Anonymized();
    $pending = Enrollment::factory()->pendingApproval()->create(['user_id' => $u->id, 'course_id' => Course::factory()->published()->create()->id]);
    $active = Enrollment::factory()->create(['user_id' => $u->id, 'course_id' => Course::factory()->published()->paid()->create()->id]);
    $other = Enrollment::factory()->pendingApproval()->create(['course_id' => Course::factory()->published()->create()->id]);

    vvT34Finalize($u);

    expect($pending->fresh()->status->value)->toBe('rejected')->and($pending->fresh()->rejection_reason)->toBe('Học sinh đã xoá tài khoản')
        ->and($active->fresh()->status->value)->toBe('active')->and($active->fresh()->activated_at)->not->toBeNull()
        ->and($other->fresh()->status->value)->toBe('pending_approval');
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $log = AuditLog::query()->where('action', 'enrollment.withdraw')->where('subject_id', $pending->id)->firstOrFail();
    expect($log->actor_id)->toBeNull()->and($log->changes)->toEqual(['course_id' => $pending->course_id, 'user_id' => $u->id]);
});

test('(h) gio rong va go ma; nguoi khac khong bi dung toi', function () {
    $u = vvT34Anonymized();
    $other = vvT34Create();
    $coupon = Coupon::factory()->percent(10)->create();
    $courses = Course::factory()->published()->paid()->count(2)->create();
    $cart = Cart::factory()->state(['user_id' => $u->id])->withCourses($courses)->withCoupon($coupon)->create();
    $otherCart = Cart::factory()->state(['user_id' => $other->id])->withCourses($courses)->create();

    vvT34Finalize($u);

    expect(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(0)
        ->and($cart->fresh()->coupon_id)->toBeNull()
        ->and(DB::table('cart_items')->where('cart_id', $otherCart->id)->count())->toBe(2);
});

test('(h) chay job 2 lan -> khong loi, khong them audit, khong them log don', function () {
    $u = vvT34Anonymized();
    $order = vvT34PendingOrder($u, withAttempt: false);
    Enrollment::factory()->pendingApproval()->create(['user_id' => $u->id, 'course_id' => Course::factory()->published()->create()->id]);
    Cart::factory()->state(['user_id' => $u->id])->withCourses([Course::factory()->published()->paid()->create()])->create();

    FinalizeAccountDeletionJob::dispatchSync($u->id);
    $audits = AuditLog::query()->count();
    $logs = DB::table('order_status_logs')->where('order_id', $order->id)->count();
    $updated = Enrollment::query()->where('user_id', $u->id)->first()->updated_at;

    $this->travel(5)->minutes();
    FinalizeAccountDeletionJob::dispatchSync($u->id);

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe($logs)
        ->and(Enrollment::query()->where('user_id', $u->id)->first()->updated_at->equalTo($updated))->toBeTrue()
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('(h) tai khoan CHUA an danh -> job khong dong vao gi (an toan)', function () {
    $u = vvT34Create();
    $order = vvT34PendingOrder($u, withAttempt: false);
    $enr = Enrollment::factory()->pendingApproval()->create(['user_id' => $u->id, 'course_id' => Course::factory()->published()->create()->id]);
    $cart = Cart::factory()->state(['user_id' => $u->id])->withCourses([Course::factory()->published()->paid()->create()])->create();

    FinalizeAccountDeletionJob::dispatchSync($u->id);
    FinalizeAccountDeletionJob::dispatchSync(999999);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($enr->fresh()->status->value)->toBe('pending_approval')
        ->and(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(1);
});

test('(h) job la ShouldBeUnique theo user, queue default, tries 5, backoff 10/60/300/900', function () {
    $job = new FinalizeAccountDeletionJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('finalize-account-deletion:42')
        ->and($job->queue)->toBe('default')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 60, 300, 900]);
});

test('(h) pha A chi dispatch job SAU commit va chi mang user_id', function () {
    Queue::fake();
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    Queue::assertPushed(FinalizeAccountDeletionJob::class, fn ($j) => $j->userId === $me->id);
    Queue::assertPushedTimes(FinalizeAccountDeletionJob::class, 1);
});

test('(h) pha A that bai (409) thi KHONG dispatch job', function () {
    Queue::fake();
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    vvT34PendingOrder($me, 'pending', now()->addMinutes(20));

    vvT34Delete($code)->assertStatus(409);

    Queue::assertNothingPushed();
});

test('(h) luong day du: xoa -> job (sync) don gio yeu cau, khoa dang hoc giu, khong mail hoc sinh', function () {
    Mail::fake();
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $order = vvT34PendingOrder($me, withAttempt: false);
    $pending = Enrollment::factory()->pendingApproval()->create(['user_id' => $me->id, 'course_id' => Course::factory()->published()->create()->id]);
    $active = Enrollment::factory()->create(['user_id' => $me->id, 'course_id' => Course::factory()->published()->paid()->create()->id]);
    Cart::factory()->state(['user_id' => $me->id])->withCourses([Course::factory()->published()->paid()->create()])->create();
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($pending->fresh()->status->value)->toBe('rejected')
        ->and($active->fresh()->status->value)->toBe('active')
        ->and(DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')->where('carts.user_id', $me->id)->count())->toBe(0);
    Mail::assertNotQueued(EnrollmentDecisionMail::class);
    Mail::assertNotSent(EnrollmentDecisionMail::class);
});

test('(j) danh sach duyet dang ky hien "Tai khoan da xoa", khong 500, khong lo email/SDT', function () {
    $course = Course::factory()->published()->create();
    $u = vvT34Anonymized(['grade_level' => 9]);
    $pending = Enrollment::factory()->pendingApproval()->create(['user_id' => $u->id, 'course_id' => $course->id]);

    vvStaffLogin(vvStaffUser('pageManager'));
    $res = vvAdminGet('/admin/enrollment-requests')->assertOk();

    expect($res->json('data.0.student'))->toBe([
        'id' => $u->id, 'name' => 'Tài khoản đã xoá', 'grade_level' => 9, 'email_masked' => null, 'phone_masked' => null,
    ]);

    // Sau khi job rút yêu cầu, bản ghi nằm ở danh sách `rejected` với lý do rút.
    vvT34Finalize($u);
    $rejected = vvAdminGet('/admin/enrollment-requests?status=rejected')->assertOk();
    expect($rejected->json('data.0.id'))->toBe($pending->id)
        ->and($rejected->json('data.0.rejection_reason'))->toBe('Học sinh đã xoá tài khoản')
        ->and($rejected->json('data.0.student.name'))->toBe('Tài khoản đã xoá');
});

test('(j) duyet yeu cau cua tai khoan da an danh khong gui mail va khong 500', function () {
    Mail::fake();
    $course = Course::factory()->published()->create();
    $u = vvT34Anonymized();
    $pending = Enrollment::factory()->pendingApproval()->create(['user_id' => $u->id, 'course_id' => $course->id]);

    vvStaffLogin(vvStaffUser('pageManager'));
    test()->postJson(vvAdminUrl("/admin/enrollment-requests/{$pending->id}/approve"), [], vvAdminHeaders())->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('(k) users:purge-unverified khong dung toi tai khoan da an danh', function () {
    $old = now()->subDays(30);
    $anon = User::factory()->student()->create(['created_at' => $old]);
    DB::table('users')->where('id', $anon->id)->update(['anonymized_at' => now(), 'email' => null, 'phone' => null, 'email_verified_at' => null, 'phone_verified_at' => null]);
    $stale = User::factory()->student()->create(['created_at' => $old]);

    $this->artisan('users:purge-unverified', ['--days' => 7])->assertSuccessful();

    expect(User::query()->whereKey($anon->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($stale->id)->exists())->toBeFalse();
});

test('(k) lenh dry-run cung khong dem tai khoan da an danh', function () {
    $anon = User::factory()->student()->create(['created_at' => now()->subDays(30)]);
    DB::table('users')->where('id', $anon->id)->update(['anonymized_at' => now(), 'email' => null, 'phone' => null]);

    $this->artisan('users:purge-unverified', ['--dry-run' => true, '--days' => 7])->expectsOutputToContain('0 tài khoản sẽ xoá')->assertSuccessful();
});
