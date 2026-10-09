<?php

use App\Mail\OrderPaidMail;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30]);
    Mail::fake();
});

test('QA AC: OrderPaidMail render - link /thanh-toan/da-gui/{code}, co ten khoa, khong STK/ma giao dich/ghi chu', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $order = vvT24Order(null, [], 'manual', 1);
    vvT39Approve($order, ['payment_reference' => 'FT-SECRET-777', 'note' => 'GHICHUNOIBO'])->assertOk();

    Mail::assertQueued(OrderPaidMail::class, function (OrderPaidMail $m) use ($order) {
        $html = $m->render();

        return str_contains($html, '/thanh-toan/da-gui/'.$order->code)
            && str_contains($html, "Khoa {$order->code}-1")
            && ! str_contains($html, 'FT-SECRET-777') && ! str_contains($html, 'GHICHUNOIBO')
            && ! preg_match('/s[ốo]\s*t[àa]i\s*kho[ảa]n|STK|ngân hàng/iu', $html);
    });
});

test('QA phan quyen: giao vien goi ma co that va ma khong ton tai -> cung 403 (khong lo ton tai)', function () {
    $order = vvT24Order();
    vvStaffLogin(vvStaffUser('teacher'));
    foreach (['approve', 'cancel', 'notes'] as $a) {
        $real = vvT39Post($order, $a, ['confirm' => true, 'reason' => 'Lý do hợp lệ', 'body' => 'x']);
        $fake = vvT39Post('VV-KHONGCO-999', $a, ['confirm' => true, 'reason' => 'Lý do hợp lệ', 'body' => 'x']);
        $real->assertForbidden();
        $fake->assertForbidden();
        expect(collect($fake->json())->except('request_id')->all())->toEqual(collect($real->json())->except('request_id')->all());
    }
});

test('QA quan tri: ma khong ton tai -> 404 cho nguoi du quyen', function () {
    vvStaffLogin(vvStaffUser('admin'));
    vvT39Post('VV-KHONGCO-999', 'approve', ['confirm' => true])->assertNotFound();
});

dataset('qa_bad_text', [
    'script' => ['<script>alert(1)</script>'],
    'rlo U+202E' => ["abc\u{202E}def ghi"],
    'zwsp U+200B' => ["abc\u{200B}def ghi"],
]);

test('QA input: script/U+202E/U+200B o note, reason, transaction_ref -> 422, DB khong doi', function (string $bad) {
    vvStaffLogin(vvStaffUser('admin'));
    $pending = vvT24Order();
    $cancelled = vvT39Cancelled('expired', 1);
    $b1 = vvT39Snapshot($pending);
    $b2 = vvT39Snapshot($cancelled);

    vvT39Post($pending, 'approve', ['confirm' => true, 'payment_reference' => $bad])->assertStatus(422)->assertJsonValidationErrors('payment_reference');
    vvT39Post($pending, 'approve', ['confirm' => true, 'note' => $bad])->assertStatus(422)->assertJsonValidationErrors('note');
    vvT39Post($pending, 'cancel', ['reason' => $bad])->assertStatus(422)->assertJsonValidationErrors('reason');
    vvT39Post($pending, 'cancel', ['reason' => 'Lý do hợp lệ', 'note' => $bad])->assertStatus(422)->assertJsonValidationErrors('note');
    vvT39Post($pending, 'notes', ['body' => $bad])->assertStatus(422)->assertJsonValidationErrors('body');

    expect(vvT39Snapshot($pending))->toBe($b1)->and(vvT39Snapshot($cancelled))->toBe($b2);
    Mail::assertNothingQueued();
})->with('qa_bad_text');

test('QA input: bien do dai - note 1000 ok / 1001 loi; reason 500 ok / 501 loi; ref 100 ok / 101 loi; tieng Viet co dau', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $o = vvT24Order();
    vvT39Post($o, 'notes', ['body' => str_repeat('ẩ', 1000)])->assertCreated();
    vvT39Post($o, 'notes', ['body' => str_repeat('ẩ', 1001)])->assertStatus(422);
    vvT39Post($o, 'cancel', ['reason' => str_repeat('ệ', 501)])->assertStatus(422);
    vvT39Post($o, 'approve', ['confirm' => true, 'payment_reference' => str_repeat('a', 101)])->assertStatus(422);
    vvT39Post($o, 'approve', ['confirm' => true, 'payment_reference' => str_repeat('a', 100)])->assertOk();
});

test('QA 409 nhanh MoMo / paid / lan 2: khong doi DB, khong thu, khong audit', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $momo = vvT24Order(null, ['payment_method' => 'momo'], 'pending');
    $b = vvT39Snapshot($momo);
    vvT39Approve($momo)->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_MANUAL');
    vvT39Post($momo, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409);
    expect(vvT39Snapshot($momo))->toBe($b);

    $o = vvT24Order();
    vvT39Approve($o)->assertOk();
    $after = vvT39Snapshot($o);
    $audits = AuditLog::query()->count();
    vvT39Approve($o)->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    vvT39Post($o, 'cancel', ['reason' => 'Lý do hợp lệ'])->assertStatus(409);
    expect(vvT39Snapshot($o))->toBe($after)->and(AuditLog::query()->count())->toBe($audits);
    Mail::assertQueued(OrderPaidMail::class, 1);
});

test('QA ghi chu noi bo: khong co route sua/xoa; khong lot GET /orders/{code} hoc sinh', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $o = vvT24Order();
    vvT39Post($o, 'notes', ['body' => 'BIMAT-NOIBO-123'])->assertCreated();
    foreach (['PUT', 'PATCH', 'DELETE'] as $m) {
        expect(test()->json($m, vvApiUrl("/admin/orders/{$o->code}/notes/1"), [])->status())->toBeIn([401, 404, 405]);
    }
    expect(DB::table('order_notes')->where('order_id', $o->id)->count())->toBe(1);
    vvResetClient();
    vvActAsStudent($o->user);
    $res = test()->getJson(vvApiUrl("/orders/{$o->code}"), vvWebHeaders())->assertOk();
    expect(json_encode($res->json()))->not->toContain('BIMAT-NOIBO-123');
});

test('QA throttle: route approve/cancel/notes dung limiter admin-order-action, 30/phut -> 429', function () {
    foreach (['approve', 'cancel', 'notes'] as $n) {
        expect(Route::getRoutes()->getByName("admin.orders.{$n}")->gatherMiddleware())->toContain('throttle:admin-order-action');
    }
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);
    $o = vvT24Order();
    $status = null;
    for ($i = 0; $i < 32; $i++) {
        $status = vvT39Post($o, 'notes', ['body' => "ghi chu {$i}"])->status();
        if ($status === 429) {
            break;
        }
    }
    expect($status)->toBe(429)->and($i)->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(31);
});
