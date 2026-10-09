<?php

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
});

function vvT24Detail(Order $o)
{
    return vvAdminGet("/admin/orders/{$o->code}");
}

function vvT24ViewPiiCount(Order $o): int
{
    return AuditLog::query()->where('action', 'order.view_pii')->where('subject_type', $o->getMorphClass())->where('subject_id', $o->id)->count();
}

test('chi tiet: day du khoa theo contract, email/SDT day du, KHONG co thong tin phu huynh, dung 1 audit order.view_pii moi lan goi', function () {
    $admin = vvStaffUser('admin', ['name' => 'Trần Thị Bình']);
    $student = User::factory()->student()->verified()->create([
        'name' => 'Nguyễn Văn An', 'email' => 'nguyenvanan@gmail.com', 'phone' => '0901234123',
        'parent_email' => 'phuhuynh.bimat@example.com', 'parent_phone' => '0988777666',
    ]);
    $student->forceFill(['phone_verified_at' => null])->save();
    $coupon = Coupon::factory()->create(['code' => 'HE2026']);
    $order = vvT24Order($student, ['customer_note' => 'Gọi sau 18h giúp em', 'coupon_id' => $coupon->id, 'coupon_code' => 'HE2026', 'payment_reference' => null], 'manual', 0);
    $courseA = Course::factory()->published()->paid(300000)->create();
    $courseB = Course::factory()->unpublished()->paid(250000)->create();
    $courseC = Course::factory()->paid(120000)->create(); // draft
    $courseD = Course::factory()->published()->paid(90000)->create();
    foreach ([[$courseA, 'Toán 9 nâng cao'], [$courseB, 'Ngữ văn 9'], [$courseC, 'Bản nháp'], [$courseD, 'Đã xoá']] as [$c, $t]) {
        vvT24Item($order, $c, $t, 100000);
    }
    $courseD->delete(); // xoá mềm
    DB::table('order_status_logs')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => 'pending', 'reason' => null, 'actor_type' => 'user', 'actor_id' => $student->id, 'meta' => json_encode(['items' => 4, 'secret_pii' => 'x@y.z']), 'created_at' => now()]);
    DB::table('order_notes')->insert(['order_id' => $order->id, 'author_id' => $admin->id, 'body' => 'Đã gọi 9h', 'created_at' => now()]);

    vvStaffLogin(vvStaffUser('pageManager'));
    $before = vvT24ViewPiiCount($order);
    $r = vvT24Detail($order)->assertOk();
    $j = $r->json();

    expect(array_keys($j))->toBe(['code', 'status', 'status_reason', 'payment_method', 'needs_review', 'needs_review_reasons', 'subtotal', 'discount', 'total', 'coupon_code', 'payment_reference', 'customer_note', 'cancel_reason', 'created_at', 'expires_at', 'paid_at', 'cancelled_at', 'refunded_at', 'refund_note', 'confirmed_by', 'refunded_by', 'student', 'items', 'status_logs', 'notes', 'attempts', 'approval'])
        ->and($j['student'])->toBe(['id' => $student->id, 'name' => 'Nguyễn Văn An', 'email' => 'nguyenvanan@gmail.com', 'email_verified' => true, 'phone' => '0901234123', 'phone_verified' => false, 'account_status' => 'active', 'is_deleted' => false])
        ->and($j['customer_note'])->toBe('Gọi sau 18h giúp em')
        ->and($j['coupon_code'])->toBe('HE2026')
        ->and(collect($j['items'])->pluck('course_status')->all())->toBe(['published', 'unpublished', 'draft', 'deleted'])
        ->and($j['items'][0])->toBe(['course_id' => $courseA->id, 'title' => 'Toán 9 nâng cao', 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000, 'course_status' => 'published', 'current_price' => 300000])
        ->and($j['items'][3]['current_price'])->toBeNull()
        ->and($j['status_logs'][0])->toBe(['from' => null, 'to' => 'pending', 'reason' => null, 'actor_type' => 'user', 'actor' => ['id' => $student->id, 'name' => 'Nguyễn Văn An'], 'meta' => ['items' => 4], 'created_at' => $j['status_logs'][0]['created_at']])
        ->and($j['notes'][0]['author'])->toBe(['id' => $admin->id, 'name' => 'Trần Thị Bình'])
        ->and($j['attempts'])->toBe([])
        ->and($r->getContent())->not->toContain('phuhuynh')->not->toContain('0988777666')->not->toContain('parent')->not->toContain('secret_pii');

    expect(vvT24ViewPiiCount($order))->toBe($before + 1);
    vvT24Detail($order)->assertOk();
    expect(vvT24ViewPiiCount($order))->toBe($before + 2);
    $log = AuditLog::query()->where('action', 'order.view_pii')->where('subject_id', $order->id)->latest('id')->first();
    expect($log->actor_role)->toBe('quan_ly_trang')->and($log->changes['code'])->toBe($order->code)->and(json_encode($log->changes))->not->toContain('nguyenvanan')->not->toContain('Gọi sau');
});

test('chi tiet: ma khong ton tai -> 404 NOT_FOUND; lan loi khong ghi audit', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $before = AuditLog::query()->where('action', 'order.view_pii')->count();

    vvAdminGet('/admin/orders/VV000000XXXXXX')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    expect(AuditLog::query()->where('action', 'order.view_pii')->count())->toBe($before);
});

test('chi tiet: tai khoan da an danh -> is_deleted, ten co dinh, email/SDT null, khong 500', function () {
    $student = User::factory()->student()->verified()->create();
    $student->forceFill(['name' => 'Tài khoản đã xoá', 'email' => null, 'phone' => null, 'anonymized_at' => now(), 'status' => 'locked'])->save();
    $order = vvT24Order($student, ['status_reason' => 'account_deleted'], 'cancelled');

    vvStaffLogin(vvStaffUser('admin'));
    $j = vvT24Detail($order)->assertOk()->json();

    expect($j['student'])->toBe(['id' => $student->id, 'name' => 'Tài khoản đã xoá', 'email' => null, 'email_verified' => false, 'phone' => null, 'phone_verified' => false, 'account_status' => 'locked', 'is_deleted' => true])
        ->and($j['approval']['can_approve_late'])->toBeFalse()
        ->and(collect($j['approval']['warnings'])->pluck('code')->all())->toContain('ACCOUNT_DELETED', 'ACCOUNT_LOCKED');
});

test('approval: ma tran pending / cancelled trong va ngoai 30 ngay / account_deleted / khong phai manual / da paid', function () {
    config(['orders.manual.approval_window_days' => 30]);
    $cases = [
        'pending' => [vvT24Order(), [true, false, true, null]],
        'huy 29 ngay' => [vvT24Order(null, ['cancelled_at' => now()->subDays(29), 'status_reason' => 'expired'], 'cancelled', 1), [false, true, false, 'cancelled_at+30']],
        'huy 31 ngay' => [vvT24Order(null, ['cancelled_at' => now()->subDays(31), 'status_reason' => 'expired'], 'cancelled', 1), [false, false, false, 'cancelled_at+30']],
        'account_deleted' => [vvT24Order(null, ['cancelled_at' => now()->subDay(), 'status_reason' => 'account_deleted'], 'cancelled', 1), [false, false, false, 'cancelled_at+30']],
        'khong phai manual (cancelled)' => [vvT24Order(null, ['payment_method' => 'momo', 'cancelled_at' => now()], 'cancelled', 1), [false, false, false, null]],
        'khong phai manual (pending)' => [vvT24Order(null, ['payment_method' => 'momo'], 'pending', 1), [false, false, false, null]],
        'paid' => [vvT24Order(null, ['payment_method' => 'manual'], 'paid', 1), [false, false, false, null]],
    ];

    vvStaffLogin(vvStaffUser('admin'));
    foreach ($cases as $name => [$order, [$approve, $late, $cancel, $until]]) {
        $a = vvT24Detail($order)->assertOk()->json('approval');
        expect([$a['can_approve'], $a['can_approve_late'], $a['can_cancel']])->toBe([$approve, $late, $cancel], $name);
        if ($until === null) {
            expect($a['approval_window_until'])->toBeNull($name);
        } else {
            expect(strtotime($a['approval_window_until']))->toBe($order->fresh()->cancelled_at->copy()->addDays(30)->getTimestamp(), $name);
        }
    }

    // Cửa sổ = 0 tắt duyệt muộn.
    config(['orders.manual.approval_window_days' => 0]);
    $a = vvT24Detail($cases['huy 29 ngay'][0])->assertOk()->json('approval');
    expect($a['can_approve_late'])->toBeFalse()->and($a['approval_window_until'])->toBeNull();
});

test('approval.warnings: COURSE_UNPUBLISHED, COURSE_DELETED, ALREADY_OWNED, ACCOUNT_LOCKED, ACCOUNT_DELETED (+ khong co cho don khong con duyet duoc)', function () {
    $student = User::factory()->student()->verified()->locked()->create();
    $order = vvT24Order($student, [], 'manual', 0);
    $pub = Course::factory()->published()->paid()->create();
    $unpub = Course::factory()->unpublished()->paid()->create();
    $owned = Course::factory()->published()->paid()->create();
    $gone = Course::factory()->published()->paid()->create();
    foreach ([$pub, $unpub, $owned, $gone] as $c) {
        vvT24Item($order, $c);
    }
    $gone->delete();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $owned->id, 'order_id' => null]);

    vvStaffLogin(vvStaffUser('admin'));
    $w = vvT24Detail($order)->assertOk()->json('approval.warnings');

    expect($w)->toBe([
        ['code' => 'ACCOUNT_LOCKED'],
        ['code' => 'COURSE_UNPUBLISHED', 'course_id' => $unpub->id, 'title' => $unpub->title],
        ['code' => 'ALREADY_OWNED', 'course_id' => $owned->id, 'title' => $owned->title],
        ['code' => 'COURSE_DELETED', 'course_id' => $gone->id, 'title' => $gone->title],
    ]);

    // Đơn đã paid: không còn cảnh báo.
    $paid = vvT24Order($student, ['payment_method' => 'manual'], 'paid', 1);
    expect(vvT24Detail($paid)->json('approval.warnings'))->toBe([]);
});

test('approval.late_approval_warnings: [] khi khong duyet muon duoc; ALREADY_OWNED / COUPON_OVER_LIMIT / COUPON_ALREADY_USED dung du lieu', function () {
    $student = User::factory()->student()->verified()->create();
    $owned = Course::factory()->published()->paid()->create();
    $free = Course::factory()->published()->paid()->create();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $owned->id, 'order_id' => Order::factory()->paid()->create(['user_id' => $student->id])->id]);

    $over = Coupon::factory()->create(['code' => 'DAYLUOT', 'max_uses' => 3, 'used_count' => 3]);
    $used = Coupon::factory()->create(['code' => 'DADUNG', 'max_uses' => 2, 'used_count' => 2]);
    $ok = Coupon::factory()->create(['code' => 'CONCHO', 'max_uses' => 5, 'used_count' => 1]);
    $otherOrder = Order::factory()->paid()->create(['user_id' => $student->id]);
    CouponUsage::query()->forceCreate(['coupon_id' => $used->id, 'user_id' => $student->id, 'order_id' => $otherOrder->id, 'used_at' => now()]);

    $mk = function (?Coupon $coupon, array $courses, string $state = 'cancelled', array $extra = []) use ($student) {
        $o = vvT24Order($student, array_merge(['status_reason' => 'expired', 'cancelled_at' => now()->subDays(2), 'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code], $extra), $state, 0);
        // Mỗi đơn pending/cancelled cùng HS cần trùng pending_flag? Chỉ pending bị ràng buộc: các đơn ở đây là cancelled.
        foreach ($courses as $c) {
            vvT24Item($o, $c);
        }

        return $o;
    };

    $a = $mk(null, [$owned, $free]);
    $b = $mk($over, [$free]);
    $c = $mk($used, [$free]);
    $d = $mk($ok, [$free]);
    $e = $mk($over, [$owned], 'cancelled', ['cancelled_at' => now()->subDays(40)]); // quá cửa sổ: không dự báo
    $pending = vvT24Order($student, [], 'manual', 0);
    vvT24Item($pending, $owned);

    vvStaffLogin(vvStaffUser('admin'));
    $late = fn (Order $o) => vvT24Detail($o)->assertOk()->json('approval.late_approval_warnings');

    expect($late($a))->toBe([['code' => 'ALREADY_OWNED', 'course_id' => $owned->id, 'title' => $owned->title]])
        ->and($late($b))->toBe([['code' => 'COUPON_OVER_LIMIT', 'coupon_code' => 'DAYLUOT']])
        ->and($late($c))->toBe([['code' => 'COUPON_ALREADY_USED', 'coupon_code' => 'DADUNG']]) // đã dùng ở đơn khác: chỉ báo ALREADY_USED, như markPaid
        ->and($late($d))->toBe([])
        ->and($late($e))->toBe([])
        ->and($late($pending))->toBe([]);
});

test('late_approval_warnings khop needs_review_reasons sau khi markPaid (tru late_payment)', function () {
    $student = User::factory()->student()->verified()->create();
    $owned = Course::factory()->published()->paid()->create();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $owned->id, 'order_id' => Order::factory()->paid()->create(['user_id' => $student->id])->id]);
    $coupon = Coupon::factory()->create(['code' => 'DAYLUOT', 'max_uses' => 1, 'used_count' => 1]);
    $order = vvT24Order($student, ['status_reason' => 'expired', 'cancelled_at' => now()->subDay(), 'coupon_id' => $coupon->id, 'coupon_code' => 'DAYLUOT'], 'cancelled', 0);
    vvT24Item($order, $owned);

    vvStaffLogin(vvStaffUser('admin'));
    $codes = collect(vvT24Detail($order)->json('approval.late_approval_warnings'))->pluck('code')->sort()->values()->all();
    expect($codes)->toBe(['ALREADY_OWNED', 'COUPON_OVER_LIMIT']);

    app(OrderFulfillmentService::class)->markPaid($order->fresh(), 'ipn');
    $reasons = vvT24Detail($order)->assertOk()->json('needs_review_reasons');

    expect($reasons)->toEqualCanonicalizing(['late_payment', 'already_owned', 'coupon_over_limit']);
    expect(Order::query()->find($order->id)->needs_review)->toBeTrue();
});

test('markPaid: coupon_already_used vao meta.review khi HS da dung ma o don khac; needs_review_reasons = [] khi chua paid', function () {
    $student = User::factory()->student()->verified()->create();
    $course = Course::factory()->published()->paid()->create();
    $coupon = Coupon::factory()->create(['max_uses' => 10]);
    $first = Order::factory()->paid()->create(['user_id' => $student->id]);
    CouponUsage::query()->forceCreate(['coupon_id' => $coupon->id, 'user_id' => $student->id, 'order_id' => $first->id, 'used_at' => now()]);
    $order = vvT24Order($student, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code], 'manual', 0);
    vvT24Item($order, $course);

    vvStaffLogin(vvStaffUser('admin'));
    expect(vvT24Detail($order)->json('needs_review_reasons'))->toBe([]);

    app(OrderFulfillmentService::class)->markPaid($order->fresh(), 'ipn');
    expect(vvT24Detail($order)->json('needs_review_reasons'))->toBe(['coupon_already_used'])
        ->and($coupon->fresh()->used_count)->toBe(0);
});

test('chi tiet don cong: attempts khong lo pay_url; status_reason/hoan tien/ nguoi duyet hien thi', function () {
    $staff = vvStaffUser('pageManager', ['name' => 'Lê Văn Cường']);
    $order = vvT24Order(null, ['payment_method' => 'momo'], 'paid', 1);
    PaymentAttempt::factory()->create(['order_id' => $order->id, 'gateway' => 'momo', 'gateway_order_id' => $order->code.'-1']);
    DB::table('orders')->where('id', $order->id)->update(['confirmed_by' => $staff->id, 'refunded_by' => $staff->id, 'refund_note' => 'Hoàn qua CK']);

    vvStaffLogin(vvStaffUser('admin'));
    $r = vvT24Detail($order)->assertOk();

    expect($r->json('attempts'))->toHaveCount(1)
        ->and(array_keys($r->json('attempts.0')))->toBe(['id', 'gateway', 'gateway_order_id', 'amount', 'status', 'result_code', 'result_message', 'expires_at', 'created_at'])
        ->and($r->getContent())->not->toContain('fake-pay')->not->toContain('pay_url')
        ->and($r->json('confirmed_by'))->toBe(['id' => $staff->id, 'name' => 'Lê Văn Cường'])
        ->and($r->json('refunded_by.name'))->toBe('Lê Văn Cường')
        ->and($r->json('refund_note'))->toBe('Hoàn qua CK');
});

test('pending-count: chi dem don manual pending; expiring_soon dung moc 12 gio; chi 1 truy van dem', function () {
    $base = Order::query()->where('status', 'pending')->where('payment_method', 'manual')->count();
    vvT24Order(null, ['expires_at' => now()->addHours(11)], 'manual');
    vvT24Order(null, ['expires_at' => now()->addHours(13)], 'manual');
    vvT24Order(null, ['expires_at' => now()->subMinute()], 'manual'); // quá hạn, job chưa chạy: vẫn cần xử lý
    vvT24Order(null, ['payment_method' => 'momo', 'expires_at' => now()->addHours(1)], 'manual'); // đơn cổng: không đếm
    vvT24Order(null, ['payment_method' => 'manual'], 'paid');
    vvT24Order(null, [], 'cancelled');

    vvStaffLogin(vvStaffUser('pageManager'));
    vvAdminGet('/admin/orders/pending-count')->assertOk(); // làm nóng

    DB::flushQueryLog();
    DB::enableQueryLog();
    $r = vvAdminGet('/admin/orders/pending-count')->assertOk();
    $orderQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `orders`'))->count();
    DB::disableQueryLog();

    expect($r->json())->toBe(['pending_manual' => $base + 3, 'expiring_soon' => $base + 2])
        ->and($orderQueries)->toBe(1);
    // Giữ nguyên cache-control không lưu.
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});
