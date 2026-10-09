<?php

use App\Mail\ManualOrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\Orders\ManualOrderNotifier;
use App\Services\Orders\ManualOrderService;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30]);
    Mail::fake();
});

test('R1: don MoMo co tien markPaid ipn + ipn lap + query -> dung 1 OrderPaidMail; don 0d (checkout) khong gui', function () {
    $student = User::factory()->student()->verified()->create();
    $order = vvT24Order($student, ['payment_method' => 'momo'], 'pending');
    $svc = app(OrderFulfillmentService::class);

    $svc->markPaid($order, 'ipn');
    $svc->markPaid(Order::find($order->id), 'ipn');
    $svc->markPaid(Order::find($order->id), 'query');

    Mail::assertQueued(OrderPaidMail::class, 1);
    Mail::assertQueued(OrderPaidMail::class, fn (OrderPaidMail $m) => $m->hasTo($student->email) && $m->orderCode === $order->code);

    $zero = vvT24Order(User::factory()->student()->verified()->create(), ['payment_method' => 'none', 'total_amount' => 0, 'subtotal_amount' => 0], 'pending');
    $svc->markPaid($zero, 'checkout');
    $paidCheckout = vvT24Order(User::factory()->student()->verified()->create(), ['payment_method' => 'none'], 'pending');
    $svc->markPaid($paidCheckout, 'checkout');
    Mail::assertQueued(OrderPaidMail::class, 1); // vẫn chỉ đơn MoMo
});

test('R2: tai khoan da an danh -> approval.can_approve_late=false + ACCOUNT_DELETED; duyet muon 409 voi thong diep "tai khoan da bi xoa"', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create();
    $order = vvT39Cancelled('user_cancelled', 1, $student);
    expect(vvAdminGet("/admin/orders/{$order->code}")->json('approval.can_approve_late'))->toBeTrue();

    $student->forceFill(['anonymized_at' => now()])->save();
    $detail = vvAdminGet("/admin/orders/{$order->code}")->assertOk();
    expect($detail->json('approval.can_approve_late'))->toBeFalse()
        ->and(collect($detail->json('approval.warnings'))->pluck('code')->all())->toContain('ACCOUNT_DELETED')
        ->and($detail->json('approval.late_approval_warnings'))->toBe([]);

    $r = vvT39Approve($order, ['late' => true])->assertStatus(409)->assertJsonPath('code', 'ORDER_APPROVAL_WINDOW_PASSED');
    expect($r->json('message'))->toContain('bị xoá')->not->toContain('quá thời hạn');
});

test('R3: huy 409 (admin) tra du errors status, status_reason, cancelled_at, can_approve_late, approval_window_until', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $cancelled = vvT39Cancelled('expired', 2);
    $paid = vvT24Order(null, [], 'paid');

    $r = vvT39Post($cancelled, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    expect($r->json('errors'))->toHaveKeys(['status', 'status_reason', 'cancelled_at', 'can_approve_late', 'approval_window_until'])
        ->and($r->json('errors.can_approve_late'))->toBeTrue()->and($r->json('errors.approval_window_until'))->not->toBeNull();

    $r = vvT39Post($paid, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409)->assertJsonPath('code', 'ORDER_STATUS_CHANGED');
    expect($r->json('errors'))->toHaveKeys(['status', 'status_reason', 'cancelled_at', 'can_approve_late', 'approval_window_until'])
        ->and($r->json('errors.can_approve_late'))->toBeFalse();
});

test('S1: thu huy doc lai hoc sinh SAU commit: an danh giua chung -> khong gui; binh thuong -> gui', function () {
    $student = User::factory()->student()->verified()->create();
    $order = vvT24Order($student);
    $notifier = app(ManualOrderNotifier::class);

    $notifier->cancelled($order, $student->id, 'admin_cancelled', 'Lý do');
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);

    $student->forceFill(['anonymized_at' => now()])->save();
    $notifier->cancelled($order, $student->id, 'admin_cancelled', 'Lý do');
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
});

test('S1b: tai khoan bi an danh trong luc huy (truoc afterCommit) -> don huy nhung khong co thu', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create();
    $order = vvT24Order($student);

    // Mô phỏng: xoá tài khoản commit sau khi huỷ đọc `users` nhưng trước afterCommit → callback chạy trước các afterCommit khác.
    DB::afterCommit(fn () => $student->forceFill(['anonymized_at' => now()])->save());
    DB::transaction(fn () => app(ManualOrderService::class)->cancelByStaff($order, auth()->user() ?? User::factory()->admin()->create(), 'Lý do huỷ', null));

    expect($order->fresh()->status->value)->toBe('cancelled');
    Mail::assertNotQueued(ManualOrderCancelledMail::class);
});

test('S2: duyet (thuong) don cua tai khoan da an danh -> log warning chi co ma don, khong PII', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->verified()->create(['email' => 'secret.pii@example.com']);
    $order = vvT24Order($student);
    $student->forceFill(['anonymized_at' => now()])->save();
    Log::spy();

    vvT39Approve($order)->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === 'manual_order.approved_deleted_account' && $ctx === ['order' => $order->code])->once();
    Mail::assertNotQueued(OrderPaidMail::class);
});

test('S4: order_status_logs va order_notes append-only qua Eloquent (update/delete/save ném LogicException); INSERT van chay', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24Order();
    vvT39Approve($order)->assertOk();
    vvT39Post($order, 'notes', ['body' => 'ghi chú'])->assertCreated();

    $log = OrderStatusLog::query()->where('order_id', $order->id)->firstOrFail();
    $note = OrderNote::query()->where('order_id', $order->id)->firstOrFail();

    expect(fn () => $log->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(fn () => $note->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
    $note->body = 'sửa';
    expect(fn () => $note->save())->toThrow(LogicException::class);
    expect(DB::table('order_notes')->where('id', $note->id)->value('body'))->toBe('ghi chú');
});
