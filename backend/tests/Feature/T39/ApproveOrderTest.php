<?php

use App\Mail\ManualOrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\ParentNoticeMail;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CouponCapacity;
use App\Services\Orders\ManualOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30, 'features.parent_notices' => true]);
    Mail::fake();
});

function vvT39Courses(Order $o): array
{
    return DB::table('order_items')->where('order_id', $o->id)->pluck('course_id')->all();
}

test('(a) duyet pending: paid, ma giao dich, confirmed_by, enrollment active moi khoa, ma +1, gio duoc don, 1 OrderPaidMail, 1 thu phu huynh, 1 audit', function () {
    $admin = vvStaffUser('pageManager', ['name' => 'Trần Thị Bình']);
    vvStaffLogin($admin);
    $student = User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']);
    $coupon = Coupon::factory()->create(['max_uses' => 5, 'used_count' => 0]);
    $order = vvT24Order($student, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'total_amount' => 200000, 'subtotal_amount' => 200000], 'manual', 2);
    $courseIds = vvT39Courses($order);
    $cart = Cart::factory()->state(['user_id' => $student->id])->withCourses(Course::query()->whereIn('id', $courseIds)->get()->all())->withCoupon($coupon)->create();
    $audits = AuditLog::query()->where('action', 'order.manual_approve')->count();

    $r = vvT39Approve($order, ['payment_reference' => 'FT26100812345', 'note' => 'Chuyển khoản VCB 10:02'])->assertOk();

    expect($r->json('status'))->toBe('paid')->and($r->json('status_reason'))->toBe('manual_confirmed')
        ->and($r->json('payment_reference'))->toBe('FT26100812345')
        ->and($r->json('confirmed_by'))->toBe(['id' => $admin->id, 'name' => 'Trần Thị Bình'])
        ->and($r->json('paid_at'))->not->toBeNull()->and($r->json('needs_review'))->toBeFalse()
        ->and($r->json('notes.0.body'))->toBe('Chuyển khoản VCB 10:02')
        ->and($r->json('approval'))->toMatchArray(['can_approve' => false, 'can_approve_late' => false, 'can_cancel' => false]);

    expect(Enrollment::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBe(2)
        ->and(CouponUsage::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($coupon->fresh()->used_count)->toBe(1)
        ->and(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(0)
        ->and($cart->fresh()->coupon_id)->toBeNull();

    $log = DB::table('order_status_logs')->where('order_id', $order->id)->orderByDesc('id')->first();
    expect([$log->from_status, $log->to_status, $log->reason, $log->actor_type, (int) $log->actor_id])->toBe(['pending', 'paid', 'manual_confirmed', 'staff', $admin->id])
        ->and(json_decode($log->meta, true))->toEqual(['source' => 'manual', 'late' => false]);

    Mail::assertQueued(OrderPaidMail::class, 1);
    Mail::assertQueued(OrderPaidMail::class, fn (OrderPaidMail $m) => $m->hasTo($student->email) && str_ends_with($m->orderUrl, '/thanh-toan/da-gui/'.$order->code));
    Mail::assertQueued(ParentNoticeMail::class, 1);

    $audit = AuditLog::query()->where('action', 'order.manual_approve')->where('subject_id', $order->id)->get();
    expect(AuditLog::query()->where('action', 'order.manual_approve')->count())->toBe($audits + 1)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->actor_id)->toBe($admin->id)
        ->and($audit[0]->changes)->toMatchArray(['status' => ['from' => 'pending', 'to' => 'paid'], 'late' => false, 'needs_review' => false, 'has_reference' => true, 'pii_fields' => ['contact', 'customer_note', 'internal_notes']])
        ->and(json_encode($audit[0]->changes))->not->toContain('FT26100812345')->not->toContain('VCB');
    // Response chi tiết KHÔNG ghi thêm order.view_pii
    expect(AuditLog::query()->where('action', 'order.view_pii')->where('subject_id', $order->id)->count())->toBe(0);
});

test('(a2) thieu mot dieu kien -> khong thu phu huynh (khong email / co tat / 0d); HS khong email xac thuc hoac da an danh -> khong OrderPaidMail', function () {
    vvStaffLogin(vvStaffUser('admin'));

    $noParent = vvT24Order(User::factory()->student()->verified()->create(['parent_email' => null]));
    vvT39Approve($noParent)->assertOk();

    config(['features.parent_notices' => false]);
    $off = vvT24Order(User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']));
    vvT39Approve($off)->assertOk();
    config(['features.parent_notices' => true]);

    $unverified = vvT24Order(User::factory()->student()->create(['parent_email' => null, 'email_verified_at' => null]));
    vvT39Approve($unverified)->assertOk();

    $zero = vvT24Order(User::factory()->student()->verified()->create(['parent_email' => 'ph@example.com']), ['total_amount' => 0, 'subtotal_amount' => 0]);
    vvT39Approve($zero)->assertOk();

    Mail::assertNotQueued(ParentNoticeMail::class);
    Mail::assertQueued(OrderPaidMail::class, 2); // $noParent + $off (đơn có tiền, email đã xác thực)
    Mail::assertNotQueued(OrderPaidMail::class, fn (OrderPaidMail $m) => $m->orderCode === $zero->code || $m->orderCode === $unverified->code);
});

test('(b) thieu / false confirm -> 422 errors.confirm; ma giao dich 101 ky tu / co < -> 422; DB khong doi', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24Order();
    $before = vvT39Snapshot($order);

    vvT39Post($order, 'approve', [])->assertStatus(422)->assertJsonValidationErrors(['confirm'], 'errors');
    vvT39Post($order, 'approve', ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors(['confirm'], 'errors');
    vvT39Approve($order, ['payment_reference' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors(['payment_reference'], 'errors');
    vvT39Approve($order, ['payment_reference' => 'FT<script>'])->assertStatus(422)->assertJsonValidationErrors(['payment_reference'], 'errors');
    vvT39Approve($order, ['note' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors(['note'], 'errors');
    vvT39Approve($order, ['note' => 'a <b>x</b>'])->assertStatus(422);
    vvT39Approve($order, ['late' => 'khong'])->assertStatus(422);

    expect(vvT39Snapshot($order))->toBe($before);
    Mail::assertNothingQueued();
});

test('(c) ma tran guard: khong phai manual, da paid/refunded, late sai, qua 30 ngay, account_deleted -> dung ma, DB khong doi, khong thu, khong audit', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $momo = vvT24Order(null, ['payment_method' => 'momo'], 'pending');
    $paid = vvT24Order(null, [], 'paid');
    $refunded = vvT24Order(null, ['status' => 'refunded'], 'pending');
    $cancelled = vvT39Cancelled('expired', 2);
    $pending = vvT24Order();
    $old = vvT39Cancelled('expired', 31);
    $deleted = vvT39Cancelled('account_deleted', 1);
    $anonStudent = User::factory()->student()->verified()->create();
    $anon = vvT39Cancelled('user_cancelled', 1, $anonStudent);
    $anonStudent->forceFill(['anonymized_at' => now()])->save();

    $cases = [
        [$momo, false, 'ORDER_NOT_MANUAL'], [$momo, true, 'ORDER_NOT_MANUAL'],
        [$paid, false, 'ALREADY_PROCESSED'], [$paid, true, 'ALREADY_PROCESSED'],
        [$refunded, false, 'ALREADY_PROCESSED'],
        [$cancelled, false, 'ORDER_STATUS_CHANGED'], [$pending, true, 'ORDER_STATUS_CHANGED'],
        [$old, true, 'ORDER_APPROVAL_WINDOW_PASSED'], [$deleted, true, 'ORDER_APPROVAL_WINDOW_PASSED'], [$anon, true, 'ORDER_APPROVAL_WINDOW_PASSED'],
    ];

    foreach ($cases as [$o, $late, $code]) {
        $before = vvT39Snapshot($o);
        vvT39Approve($o, ['late' => $late])->assertStatus(409)->assertJsonPath('code', $code);
        expect(vvT39Snapshot($o))->toBe($before);
    }
    Mail::assertNothingQueued();

    $r = vvT39Approve($cancelled)->assertStatus(409);
    expect($r->json('errors'))->toMatchArray(['status' => 'cancelled', 'status_reason' => 'expired', 'can_approve_late' => true])
        ->and($r->json('errors.approval_window_until'))->not->toBeNull();
    expect(vvT39Approve($old, ['late' => true])->json('errors'))->toHaveKeys(['cancelled_at', 'approval_window_until']);
    expect(vvT39Approve($paid)->json('errors'))->toMatchArray(['status' => 'paid']);
    vvT39Post('VVKHONGCOXXXX', 'approve', ['confirm' => true])->assertNotFound();
});

test('(c2) duyet lan 2 cung don -> 409 ALREADY_PROCESSED, enrollment/ma/thu/audit khong them', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $coupon = Coupon::factory()->create(['max_uses' => 5]);
    $order = vvT24Order(null, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code]);
    vvT39Approve($order)->assertOk();
    $snap = vvT39Snapshot($order);

    vvT39Approve($order)->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    expect(vvT39Snapshot($order))->toBe($snap)->and($coupon->fresh()->used_count)->toBe(1);
    Mail::assertQueued(OrderPaidMail::class, 1);
});

test('(d) duyet muon moi ly do huy trong 30 ngay -> paid + needs_review + late_payment, warnings du bao khop needs_review_reasons', function (string $reason) {
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);
    $order = vvT39Cancelled($reason, 3);

    $detail = vvAdminGet("/admin/orders/{$order->code}")->assertOk();
    expect($detail->json('approval.can_approve_late'))->toBeTrue()->and($detail->json('approval.late_approval_warnings'))->toBe([]);

    $r = vvT39Approve($order, ['late' => true])->assertOk();

    expect($r->json('status'))->toBe('paid')->and($r->json('needs_review'))->toBeTrue()
        ->and($r->json('needs_review_reasons'))->toBe(['late_payment'])
        ->and($r->json('confirmed_by.id'))->toBe($admin->id)
        ->and(Enrollment::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBe(1);
    $log = DB::table('order_status_logs')->where('order_id', $order->id)->orderByDesc('id')->first();
    expect(json_decode($log->meta, true))->toEqual(['source' => 'manual', 'late' => true, 'review' => ['late_payment']])
        ->and($log->from_status)->toBe('cancelled');
    expect(AuditLog::query()->where('action', 'order.manual_approve')->where('subject_id', $order->id)->first()->changes)
        ->toMatchArray(['late' => true, 'needs_review' => true, 'status' => ['from' => 'cancelled', 'to' => 'paid']]);
    Mail::assertQueued(OrderPaidMail::class, 1);
})->with(['expired', 'user_cancelled', 'admin_cancelled', 'superseded']);

test('(d2) ma da du luot -> van duyet, coupon_over_limit; HS da dung ma o don khac -> coupon_already_used; da so huu khoa -> already_owned; du bao khop ket qua', function () {
    vvStaffLogin(vvStaffUser('admin'));

    // vượt lượt
    $full = Coupon::factory()->create(['max_uses' => 1, 'used_count' => 1]);
    $a = vvT39Cancelled('expired', 2);
    $a->forceFill(['coupon_id' => $full->id, 'coupon_code' => $full->code])->save();
    $warn = vvAdminGet("/admin/orders/{$a->code}")->json('approval.late_approval_warnings');
    expect(collect($warn)->pluck('code')->all())->toBe(['COUPON_OVER_LIMIT']);
    $r = vvT39Approve($a, ['late' => true])->assertOk();
    expect($r->json('needs_review_reasons'))->toEqualCanonicalizing(['late_payment', 'coupon_over_limit'])
        ->and($full->fresh()->used_count)->toBe(2);

    // đã dùng mã ở đơn khác
    $student = User::factory()->student()->verified()->create();
    $coupon = Coupon::factory()->create(['max_uses' => 10]);
    $other = vvT24Order($student, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code], 'paid');
    CouponUsage::query()->forceCreate(['coupon_id' => $coupon->id, 'user_id' => $student->id, 'order_id' => $other->id, 'used_at' => now()]);
    $b = vvT39Cancelled('expired', 2, $student);
    $b->forceFill(['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code])->save();
    $warn = vvAdminGet("/admin/orders/{$b->code}")->json('approval.late_approval_warnings');
    expect(collect($warn)->pluck('code')->all())->toBe(['COUPON_ALREADY_USED']);
    $r = vvT39Approve($b, ['late' => true])->assertOk();
    expect($r->json('needs_review_reasons'))->toEqualCanonicalizing(['late_payment', 'coupon_already_used'])->and($coupon->fresh()->used_count)->toBe(0);

    // đã sở hữu khóa
    $owner = User::factory()->student()->verified()->create();
    $c = vvT39Cancelled('expired', 2, $owner);
    $courseId = vvT39Courses($c)[0];
    $existing = Enrollment::factory()->create(['user_id' => $owner->id, 'course_id' => $courseId, 'order_id' => null]);
    $warn = vvAdminGet("/admin/orders/{$c->code}")->json('approval.late_approval_warnings');
    expect(collect($warn)->pluck('code')->all())->toBe(['ALREADY_OWNED']);
    $r = vvT39Approve($c, ['late' => true])->assertOk();
    expect($r->json('needs_review_reasons'))->toEqualCanonicalizing(['late_payment', 'already_owned'])
        ->and(Enrollment::query()->where('user_id', $owner->id)->where('course_id', $courseId)->count())->toBe(1)
        ->and($existing->fresh()->order_id)->toBeNull();
});

test('(e) khoa ngung ban -> van duyet + cap quyen; khoa da xoa (duyet muon) -> 409 COURSE_UNAVAILABLE co ten, khong enrollment, don giu cancelled', function () {
    vvStaffLogin(vvStaffUser('admin'));

    $o = vvT24Order(null, [], 'manual', 2);
    Course::query()->whereIn('id', vvT39Courses($o))->first()->forceFill(['status' => 'unpublished'])->save();
    $r = vvT39Approve($o)->assertOk();
    expect(Enrollment::query()->where('order_id', $o->id)->where('status', 'active')->count())->toBe(2)
        ->and(collect($r->json('items'))->pluck('course_status')->sort()->values()->all())->toBe(['published', 'unpublished']);

    $late = vvT39Cancelled('expired', 2, null, 3);
    [$c1, $c2, $c3] = vvT39Courses($late);
    Course::query()->find($c1)->delete();
    Course::query()->find($c3)->delete();
    $before = vvT39Snapshot($late);
    $coursesBefore = DB::table('courses')->whereIn('id', [$c2])->value('enrollments_count');

    $r = vvT39Approve($late, ['late' => true])->assertStatus(409)->assertJsonPath('code', 'COURSE_UNAVAILABLE');
    expect(collect($r->json('errors.courses'))->pluck('id')->sort()->values()->all())->toBe(collect([$c1, $c3])->sort()->values()->all())
        ->and($r->json('errors.courses.0.title'))->toStartWith('Khoa ')
        ->and(vvT39Snapshot($late))->toBe($before)
        ->and(DB::table('courses')->where('id', $c2)->value('enrollments_count'))->toBe($coursesBefore);
    expect($late->fresh()->status->value)->toBe('cancelled');
    Mail::assertQueued(OrderPaidMail::class, 1); // chỉ đơn $o
});

test('(f) huy: ly do 4 ky tu / thieu -> 422; hop le -> admin_cancelled, cancel_reason_public, ghi chu, nha ma, gio nguyen, 1 thu co ly do, audit; HS thay ly do khong thay ghi chu', function () {
    $admin = vvStaffUser('pageManager');
    vvStaffLogin($admin);
    $student = User::factory()->student()->verified()->create();
    $coupon = Coupon::factory()->create(['max_uses' => 1]);
    $order = vvT24Order($student, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code]);
    $cart = Cart::factory()->state(['user_id' => $student->id])->withCourses(Course::query()->whereIn('id', vvT39Courses($order))->get()->all())->create();
    $before = vvT39Snapshot($order);

    vvT39Post($order, 'cancel', [])->assertStatus(422)->assertJsonValidationErrors(['reason'], 'errors');
    vvT39Post($order, 'cancel', ['reason' => 'abcd'])->assertStatus(422);
    vvT39Post($order, 'cancel', ['reason' => '   abcd  '])->assertStatus(422);
    vvT39Post($order, 'cancel', ['reason' => str_repeat('a', 501)])->assertStatus(422);
    vvT39Post($order, 'cancel', ['reason' => 'Lý do hợp lệ', 'note' => str_repeat('a', 1001)])->assertStatus(422);
    vvT39Post($order, 'cancel', ['reason' => 'Lý do <b>x</b>'])->assertStatus(422);
    expect(vvT39Snapshot($order))->toBe($before);
    Mail::assertNothingQueued();

    $r = vvT39Post($order, 'cancel', ['reason' => 'Không liên hệ được qua SĐT và Zalo', 'note' => 'Gọi 3 lần'])->assertOk();
    expect($r->json('status'))->toBe('cancelled')->and($r->json('status_reason'))->toBe('admin_cancelled')
        ->and($r->json('cancel_reason'))->toBe('Không liên hệ được qua SĐT và Zalo')
        ->and($r->json('notes.0.body'))->toBe('Gọi 3 lần')
        ->and($r->json('approval.can_approve_late'))->toBeTrue()
        ->and($r->json('confirmed_by'))->toBeNull();
    expect(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(1);
    $log = DB::table('order_status_logs')->where('order_id', $order->id)->orderByDesc('id')->first();
    expect([$log->to_status, $log->reason, $log->actor_type, (int) $log->actor_id])->toBe(['cancelled', 'admin_cancelled', 'staff', $admin->id]);

    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
    Mail::assertQueued(ManualOrderCancelledMail::class, fn ($m) => $m->variant === 'admin_cancelled' && $m->publicReason === 'Không liên hệ được qua SĐT và Zalo' && $m->hasTo($student->email));

    $audit = AuditLog::query()->where('action', 'order.manual_cancel')->where('subject_id', $order->id)->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->changes)->toMatchArray(['status' => ['from' => 'pending', 'to' => 'cancelled'], 'pii_fields' => ['contact', 'customer_note', 'internal_notes']])
        ->and(json_encode($audit[0]->changes))->not->toContain('Zalo')->not->toContain('Gọi 3');

    // Nhả chỗ mã: mã chỉ 1 lượt, đơn đã huỷ không còn giữ chỗ
    expect(app(CouponCapacity::class)->held($coupon))->toBe(0)->and(app(CouponCapacity::class)->hasRoom($coupon))->toBeTrue();

    // Hủy lần 2 → ALREADY_PROCESSED; đơn đã paid → ORDER_STATUS_CHANGED; đơn MoMo → ORDER_NOT_MANUAL
    $paid = vvT24Order(null, [], 'paid');
    $momo = vvT24Order(null, ['payment_method' => 'momo'], 'pending');
    vvT39Post($order, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    vvT39Post($paid, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409)->assertJsonPath('code', 'ORDER_STATUS_CHANGED');
    vvT39Post($momo, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_MANUAL');
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);

    // Học sinh xem chi tiết: thấy cancel_reason, không thấy ghi chú
    vvResetClient();
    vvActAsStudent($student);
    $mine = test()->getJson(vvApiUrl("/orders/{$order->code}"), vvWebHeaders())->assertOk();
    expect($mine->json('cancel_reason'))->toBe('Không liên hệ được qua SĐT và Zalo')
        ->and(json_encode($mine->json()))->not->toContain('Gọi 3')->not->toContain('notes');
});

test('(f2) huy don cua tai khoan da an danh -> huy duoc nhung khong gui thu', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create();
    $order = vvT24Order($student);
    $student->forceFill(['anonymized_at' => now()])->save();

    vvT39Post($order, 'cancel', ['reason' => 'Không liên hệ được'])->assertOk()->assertJsonPath('student.is_deleted', true);
    Mail::assertNothingQueued();
});

test('(g) ghi chu: 201, don khong doi trang thai, audit; HS khong thay; don MoMo/0d/paid cung duoc; body rong/1001 -> 422', function () {
    $admin = vvStaffUser('admin', ['name' => 'Trần Thị Bình']);
    vvStaffLogin($admin);
    $order = vvT24Order();
    $momo = vvT24Order(null, ['payment_method' => 'momo'], 'pending');
    $zero = vvT24Order(null, ['payment_method' => 'none', 'total_amount' => 0, 'subtotal_amount' => 0], 'paid');
    $before = (array) DB::table('orders')->where('id', $order->id)->first();

    $r = vvT39Post($order, 'notes', ['body' => "  Đã gọi 9h\nhẹn chiều  "])->assertCreated();
    expect($r->json())->toMatchArray(['body' => "Đã gọi 9h\nhẹn chiều", 'author' => ['id' => $admin->id, 'name' => 'Trần Thị Bình']])
        ->and($r->json())->toHaveKeys(['id', 'body', 'author', 'created_at'])
        ->and((array) DB::table('orders')->where('id', $order->id)->first())->toBe($before)
        ->and(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'order.note_add')->where('subject_id', $order->id)->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->changes)->toMatchArray(['note_id' => $r->json('id')])
        ->and(json_encode($audit[0]->changes))->not->toContain('Đã gọi');

    vvT39Post($momo, 'notes', ['body' => 'ghi chú MoMo'])->assertCreated();
    vvT39Post($zero, 'notes', ['body' => 'ghi chú 0đ'])->assertCreated();
    vvT39Post($order, 'notes', ['body' => ''])->assertStatus(422)->assertJsonValidationErrors(['body'], 'errors');
    vvT39Post($order, 'notes', ['body' => str_repeat('a', 1001)])->assertStatus(422);
    vvT39Post($order, 'notes', ['body' => '<b>x</b>'])->assertStatus(422);
    vvT39Post('VVKHONGCOXXXX', 'notes', ['body' => 'x'])->assertNotFound();
    expect(DB::table('order_notes')->where('order_id', $order->id)->count())->toBe(1);

    // HS không thấy ghi chú trên GET /orders/{code}
    $mine = $order->user;
    vvResetClient();
    vvActAsStudent($mine);
    $res = test()->getJson(vvApiUrl("/orders/{$order->code}"), vvWebHeaders())->assertOk();
    expect(json_encode($res->json()))->not->toContain('hẹn chiều');
});

test('(i) quyen: khach 401; giao vien gap 403 TRUOC validate cho approve/cancel/notes, DB khong doi', function () {
    $order = vvT24Order();
    $before = vvT39Snapshot($order);

    foreach (['approve', 'cancel', 'notes'] as $action) {
        vvT39Post($order, $action, ['confirm' => true, 'reason' => 'Lý do hợp lệ', 'body' => 'x'])->assertUnauthorized();
    }

    vvStaffLogin(vvStaffUser('teacher'));
    foreach (['approve', 'cancel', 'notes'] as $action) {
        vvT39Post($order, $action, ['confirm' => 'rac', 'reason' => 'x', 'body' => ''])->assertForbidden();
        vvT39Post($order, $action, [])->assertForbidden();
    }
    expect(vvT39Snapshot($order))->toBe($before);
    Mail::assertNotQueued(OrderPaidMail::class);
});

test('(i2) hoc sinh goi admin-api approve/cancel/notes -> 401/403, DB khong doi', function () {
    $order = vvT24Order();
    $before = vvT39Snapshot($order);

    vvActAsStudent($order->user);
    foreach (['approve', 'cancel', 'notes'] as $action) {
        expect(vvT39Post($order, $action, ['confirm' => true, 'reason' => 'Lý do hợp lệ', 'body' => 'x'])->status())->toBeIn([401, 403]);
    }
    expect(vvT39Snapshot($order))->toBe($before);
});

test('(j) FEATURE_MANUAL_PAYMENT tat: duyet / huy / ghi chu van chay', function () {
    config(['features.manual_payment' => false]);
    vvStaffLogin(vvStaffUser('admin'));
    $a = vvT24Order();
    $b = vvT24Order();

    vvT39Approve($a)->assertOk();
    vvT39Post($b, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertOk();
    vvT39Post($a, 'notes', ['body' => 'ghi chú'])->assertCreated();
});

test('khong ro ri PII: response duyet/huy khong co thong tin phu huynh; audit tim duoc qua pii_fields', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create(['parent_email' => 'bimat.ph@example.com', 'parent_phone' => '0988777666']);
    $order = vvT24Order($student);

    $r = vvT39Approve($order)->assertOk();
    expect($r->getContent())->not->toContain('bimat.ph@example.com')->not->toContain('0988777666');
    expect(AuditLog::query()->where('action', 'order.manual_approve')->where('subject_id', $order->id)->whereJsonContains('changes->pii_fields', 'contact')->count())->toBe(1);
});

test('rollback: loi trong hook after -> don van pending, khong enrollment/thu/audit', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24Order();
    $before = vvT39Snapshot($order);

    // Ghi chú trùng khoá ngoại không thể xảy ra; mô phỏng lỗi bằng tác giả không tồn tại qua service trực tiếp.
    $ghost = new User;
    $ghost->id = 99999999;
    expect(fn () => app(ManualOrderService::class)->approve($order, $ghost, false, null, 'ghi chu'))->toThrow(QueryException::class);

    expect(vvT39Snapshot($order))->toBe($before);
    Mail::assertNothingQueued();
});
