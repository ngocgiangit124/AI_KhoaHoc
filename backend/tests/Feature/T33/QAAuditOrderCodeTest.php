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

test('QA T33-1 AC1: 5 action order.* co changes.code dung ma don, code gia bi thay', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $a = vvT24Order();
    $b = vvT24Order();
    vvT39Post($a, 'approve', ['confirm' => true])->assertOk();
    vvT39Post($a, 'notes', ['body' => 'ghi chu'])->assertCreated();
    vvAdminGet("/admin/orders/{$a->code}")->assertOk();
    vvT39Post($b, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertOk();
    vvT24Post("/admin/orders/{$a->code}/refund", ['confirm' => true])->assertOk();

    foreach (['order.manual_approve' => $a, 'order.note_add' => $a, 'order.view_pii' => $a, 'order.refund' => $a, 'order.manual_cancel' => $b] as $action => $o) {
        $log = AuditLog::query()->where('action', $action)->where('subject_id', $o->id)->first();
        expect($log)->not->toBeNull("thieu $action")
            ->and($log->changes['code'])->toBe($o->code)
            ->and(array_key_first($log->changes))->toBe('code');
    }
});

test('QA T33-1: search_contact khong co code; OTP code van bi loc; code gia bi thay; subject khac khong gan', function () {
    $o = vvT24Order();
    $l = app(AuditLogger::class);
    expect($l->log('order.search_contact', null, ['code' => 'x', 'kind' => 'email'])->changes)->toBe(['kind' => 'email'])
        ->and($l->log('auth.otp', $o, ['code' => '654321', 'a' => 1])->changes)->toBe(['a' => 1])
        ->and($l->log('order.refund', $o, ['code' => 'FAKE', 'nested' => ['code' => '1']])->changes['code'])->toBe($o->code)
        ->and($l->log('course.update', $o, ['a' => 1])->changes)->toBe(['a' => 1])
        ->and($l->logAsSystem('order.manual_cancel', $o, ['code' => 'FAKE'])->changes['code'])->toBe($o->code);
});

test('QA T33-1 AC2: page 0/abc/10001/-1/1.5 -> 422, 10000 va 1 ok', function () {
    vvStaffLogin(vvStaffUser('admin'));
    foreach (['0', 'abc', '10001', '-1', '1.5'] as $p) {
        vvAdminGet('/admin/audit-logs?page='.$p)->assertStatus(422);
    }
    vvAdminGet('/admin/audit-logs?page=10000')->assertOk();
    vvAdminGet('/admin/audit-logs?page=1')->assertOk();
});

test('QA T33-1: quan ly trang khong doc duoc audit-logs', function () {
    vvStaffLogin(vvStaffUser('pageManager'));
    vvAdminGet('/admin/audit-logs?page=1')->assertForbidden();
});
