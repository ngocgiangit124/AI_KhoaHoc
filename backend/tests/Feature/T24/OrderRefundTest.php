<?php

use App\Enums\EnrollmentStatus;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
});

/**
 * Đơn `manual` đã `paid` thật qua markPaid (có enrollment gắn đơn). Gọi SAU `vvStaffLogin`: markPaid ghi audit (Auth::user()) làm
 * khởi động phiên trong tiến trình test, khiến lần đăng nhập sau không nhận được Set-Cookie.
 */
function vvT24PaidOrder(?User $student = null, int $courses = 2): Order
{
    $student ??= User::factory()->student()->verified()->create();
    $order = vvT24Order($student, [], 'manual', $courses);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    return $order->fresh();
}

test('AC26: hoan tien don manual da paid -> refunded, thu hoi enrollment cua don, refunded_by/refund_note, log + audit', function () {
    $staff = vvStaffUser('pageManager', ['name' => 'Trần Thị Bình']);
    vvStaffLogin($staff);
    $order = vvT24PaidOrder();
    $other = Enrollment::factory()->create(['user_id' => $order->user_id, 'order_id' => null]); // quyền học nguồn khác: không bị đụng
    $courseIds = DB::table('order_items')->where('order_id', $order->id)->pluck('course_id');
    $counts = Course::query()->whereIn('id', $courseIds)->pluck('enrollments_count')->all();
    expect(Enrollment::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBe(2)->and($counts)->toBe([1, 1]);

    $auditBefore = AuditLog::query()->where('action', 'order.refund')->count();
    $viewBefore = AuditLog::query()->where('action', 'order.view_pii')->count();

    $r = vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true, 'note' => 'Hoàn qua chuyển khoản VCB'])->assertOk();

    expect($r->json('status'))->toBe('refunded')
        ->and($r->json('status_reason'))->toBe('refunded')
        ->and($r->json('refund_note'))->toBe('Hoàn qua chuyển khoản VCB')
        ->and($r->json('refunded_by'))->toBe(['id' => $staff->id, 'name' => 'Trần Thị Bình'])
        ->and($r->json('refunded_at'))->not->toBeNull()
        ->and($r->json('approval'))->toMatchArray(['can_approve' => false, 'can_approve_late' => false, 'can_cancel' => false])
        ->and($r->json('student.email'))->toBe($order->user->email); // chi tiết đầy đủ nhưng response hoàn tiền KHÔNG ghi view_pii
    expect(AuditLog::query()->where('action', 'order.view_pii')->count())->toBe($viewBefore);

    $fresh = Order::query()->find($order->id);
    expect($fresh->refunded_by)->toBe($staff->id)->and($fresh->refunded_at)->not->toBeNull();
    expect(Enrollment::query()->where('order_id', $order->id)->where('status', 'revoked')->where('revoked_reason', 'refund')->count())->toBe(2)
        ->and($other->fresh()->status)->toBe(EnrollmentStatus::Active)
        ->and(Course::query()->whereIn('id', $courseIds)->pluck('enrollments_count')->all())->toBe([0, 0]);

    $log = DB::table('order_status_logs')->where('order_id', $order->id)->orderByDesc('id')->first();
    expect([$log->from_status, $log->to_status, $log->actor_type, (int) $log->actor_id])->toBe(['paid', 'refunded', 'staff', $staff->id]);

    $audit = AuditLog::query()->where('action', 'order.refund')->where('subject_id', $order->id)->first();
    expect(AuditLog::query()->where('action', 'order.refund')->count())->toBe($auditBefore + 1)
        ->and($audit->actor_id)->toBe($staff->id)
        ->and($audit->changes)->toMatchArray(['code' => $order->code, 'enrollments_revoked' => 2, 'has_note' => true, 'pii_fields' => ['contact', 'customer_note', 'internal_notes']])
        ->and(json_encode($audit->changes))->not->toContain('chuyển khoản');
});

test('hoan tien lan 2 -> 409 ALREADY_PROCESSED, khong dung them gi; chua paid -> 409 ORDER_STATUS_CHANGED', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24PaidOrder(null, 1);
    $pending = vvT24Order();
    $cancelled = vvT24Order(null, [], 'cancelled', 1);

    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true])->assertOk();
    $audits = AuditLog::query()->where('action', 'order.refund')->count();
    $logs = DB::table('order_status_logs')->where('order_id', $order->id)->count();

    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true])->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    expect(AuditLog::query()->where('action', 'order.refund')->count())->toBe($audits)
        ->and(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe($logs);

    foreach ([$pending, $cancelled] as $o) {
        vvT24Post("/admin/orders/{$o->code}/refund", ['confirm' => true])->assertStatus(409)->assertJsonPath('code', 'ORDER_STATUS_CHANGED');
        expect($o->fresh()->status->value)->toBe($o->status->value)->and($o->fresh()->refunded_by)->toBeNull();
    }
    vvT24Post('/admin/orders/VVKHONGCOXXXX/refund', ['confirm' => true])->assertNotFound();
});

test('validate: confirm bat buoc true, note <= 1000 ky tu van ban thuan; sai -> 422 va DB khong doi', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24PaidOrder(null, 1);

    vvT24Post("/admin/orders/{$order->code}/refund", [])->assertStatus(422)->assertJsonValidationErrors(['confirm'], 'errors');
    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => false])->assertStatus(422);
    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true, 'note' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors(['note'], 'errors');
    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true, 'note' => '<script>x</script>'])->assertStatus(422);
    expect($order->fresh()->status->value)->toBe('paid');

    // note trống = không có; xuống dòng được chấp nhận.
    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true, 'note' => "dòng 1\r\ndòng 2"])->assertOk()->assertJsonPath('refund_note', "dòng 1\ndòng 2");
});

test('quyen: giao vien 403 voi payload sai (truoc validate), chua dang nhap 401; don khong doi', function () {
    $order = vvT24Order(null, ['status' => 'paid', 'paid_at' => now()], 'manual', 1);
    $enrollment = Enrollment::factory()->create(['user_id' => $order->user_id, 'order_id' => $order->id]);

    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true])->assertUnauthorized();

    vvStaffLogin(vvStaffUser('teacher'));
    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => 'sai', 'note' => str_repeat('a', 5000)])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    vvT24Post("/admin/orders/{$order->code}/refund", [])->assertForbidden();
    expect($order->fresh()->status->value)->toBe('paid')->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
});

test('hoan tien khong thu hoi quyen hoc cua don khac (mua trung: already_owned giu quyen cu)', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create();
    $course = Course::factory()->published()->paid()->create();
    $first = vvT24Order($student, [], 'manual', 0);
    vvT24Item($first, $course);
    app(OrderFulfillmentService::class)->markPaid($first, 'ipn');
    $second = vvT24Order($student, [], 'manual', 0);
    vvT24Item($second, $course);
    app(OrderFulfillmentService::class)->markPaid($second, 'ipn'); // trùng: giữ enrollment của đơn 1, needs_review

    vvT24Post("/admin/orders/{$second->code}/refund", ['confirm' => true])->assertOk()->assertJsonPath('status', 'refunded');
    expect(Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->value('status'))->toBe(EnrollmentStatus::Active);
    expect(AuditLog::query()->where('action', 'order.refund')->where('subject_id', $second->id)->first()->changes['enrollments_revoked'])->toBe(0);
});

test('hoan tien khoa carts truoc orders: co gio hang van chay va khong doi gio', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create();
    $order = vvT24PaidOrder($student, 1);
    $cart = Cart::factory()->state(['user_id' => $student->id])->withCourses([Course::factory()->published()->paid()->create()])->create();

    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true])->assertOk();

    expect(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(1);
});

test('hoan tien khi mot enrollment da bi thu hoi truoc do: bo qua dong do, khong 409, dem dung', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24PaidOrder(null, 2);
    Enrollment::query()->where('order_id', $order->id)->orderBy('id')->first()->forceFill(['status' => EnrollmentStatus::Revoked, 'revoked_at' => now(), 'revoked_reason' => 'admin'])->save();

    vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true])->assertOk()->assertJsonPath('status', 'refunded');
    expect(AuditLog::query()->where('action', 'order.refund')->where('subject_id', $order->id)->first()->changes['enrollments_revoked'])->toBe(1)
        ->and(Enrollment::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBe(0);
});

test('route /admin/orders*: throttle admin-order-read cho GET, admin-order-action cho hoan tien', function () {
    $named = fn (string $n) => Route::getRoutes()->getByName($n)->gatherMiddleware();

    foreach (['admin.orders.index', 'admin.orders.pending-count', 'admin.orders.show'] as $name) {
        expect($named($name))->toContain('throttle:admin-order-read')->toContain('staff.mfa_passed')->toContain('role:admin,quan_ly_trang,giao_vien');
    }
    expect($named('admin.orders.refund'))->toContain('throttle:admin-order-action');
});
