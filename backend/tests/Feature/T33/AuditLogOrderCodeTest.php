<?php

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../T28/helpers.php';
require_once __DIR__.'/../T39/helpers.php';

beforeEach(function () {
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30, 'features.parent_notices' => true]);
    Mail::fake();
});

test('T33-1: moi audit order.* co changes.code (khong lo them PII) va /admin/audit-logs tra ra', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $approved = vvT24Order();
    $cancelled = vvT24Order();

    vvT39Post($approved, 'approve', ['confirm' => true])->assertOk();
    vvT39Post($approved, 'notes', ['body' => 'ghi chu'])->assertCreated();
    vvAdminGet("/admin/orders/{$approved->code}")->assertOk(); // order.view_pii
    vvT39Post($cancelled, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertOk();
    vvT24Post("/admin/orders/{$approved->code}/refund", ['confirm' => true])->assertOk();

    $logs = AuditLog::query()->where('action', 'like', 'order.%')->get();
    expect($logs->pluck('action')->unique()->sort()->values()->all())
        ->toBe(['order.manual_approve', 'order.manual_cancel', 'order.note_add', 'order.refund', 'order.view_pii']);
    foreach ($logs as $log) {
        $expected = $log->subject_id === $approved->id ? $approved->code : $cancelled->code;
        expect($log->changes['code'])->toBe($expected);
    }

    $items = vvAdminGet('/admin/audit-logs?action=order.manual_approve&page=1')->assertOk()->json('data');
    expect($items[0]['changes']['code'])->toBe($approved->code);
    vvAdminGet('/admin/audit-logs?page=0')->assertStatus(422);
    vvAdminGet('/admin/audit-logs?page=abc')->assertStatus(422);
});

test('T33-1: khoa code van bi loc o audit khac (OTP); order.* khong co subject Order thi khong them code', function () {
    $order = vvT24Order();
    $logger = app(AuditLogger::class);
    expect($logger->log('auth.test', $order, ['code' => '123456', 'x' => 1])->changes)->toBe(['x' => 1])
        ->and($logger->log('order.search_contact', null, ['kind' => 'email'])->changes)->toBe(['kind' => 'email'])
        ->and($logger->log('order.view_pii', $order, ['code' => 'fake', 'fields' => ['email']])->changes)->toBe(['code' => $order->code, 'fields' => ['email']]);
});
