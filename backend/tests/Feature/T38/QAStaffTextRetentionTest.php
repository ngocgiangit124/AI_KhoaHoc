<?php

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Services\Privacy\AccountDeletionFinalizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../T24/helpers.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T39/helpers.php';

/**
 * QA T38-2 — bổ sung: duyệt muộn/đổi trạng thái giữa lô, refunded, xoá tài khoản + đơn pending, API sau purge,
 * dry-run, --days, lịch, OrderNote append-only, múi giờ.
 */
beforeEach(function () {
    $this->freezeTime();
    config(['orders.manual.staff_text_retention_days' => 7, 'ops.purge_sleep_ms' => 0]);
});

function qaStOrder(string $status, array $cols, array $extra = [], ?User $student = null): Order
{
    $o = vvT24Order($student, array_merge([
        'status' => $status, 'status_reason' => $status === 'cancelled' ? 'admin_cancelled' : null,
        'customer_note' => 'giữ',
        'refund_note' => 'hoàn 0911222333', 'payment_reference' => 'CK-777', 'cancel_reason_public' => 'Lý do huỷ',
        'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60),
    ], $extra), 'manual', 1);
    if ($cols !== []) {
        DB::table('orders')->where('id', $o->id)->update($cols);
    }
    OrderNote::factory()->create(['order_id' => $o->id, 'body' => 'ghi chú nội bộ 0911']);

    return $o->fresh();
}

function qaStCleared(Order $o): bool
{
    $r = DB::table('orders')->where('id', $o->id)->first();
    $b = DB::table('order_notes')->where('order_id', $o->id)->value('body');
    $cleared = $r->refund_note === null && $r->payment_reference === null && $r->cancel_reason_public === null;
    expect($cleared)->toBe($b === OrderNote::PURGED_BODY);

    return $cleared;
}

test('N3a: đơn cancelled cũ được duyệt muộn thành paid (paid_at mới) thì KHÔNG bị xoá', function () {
    Mail::fake();
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30]);
    vvStaffLogin(vvStaffUser('admin'));
    $o = vvT39Cancelled('admin_cancelled', 10);
    DB::table('orders')->where('id', $o->id)->update(['cancel_reason_public' => 'Lý do huỷ']);
    OrderNote::factory()->create(['order_id' => $o->id, 'body' => 'ghi chú']);

    vvT39Approve($o, ['late' => true, 'payment_reference' => 'FT-LATE'])->assertOk();
    expect($o->fresh()->status->value)->toBe('paid');

    Artisan::call('orders:purge-staff-notes');

    expect(DB::table('orders')->where('id', $o->id)->value('payment_reference'))->toBe('FT-LATE')
        ->and(DB::table('order_notes')->where('order_id', $o->id)->where('body', OrderNote::PURGED_BODY)->exists())->toBeFalse();
});

test('N3b: đổi trạng thái giữa hai lô (chunk=1): cancelled cũ -> paid mới sau lô đầu không bị xoá, đơn khác vẫn xoá', function () {
    config(['ops.purge_chunk' => 1]);
    $orders = collect(range(1, 4))->map(fn () => qaStOrder('cancelled', ['cancelled_at' => now()->subDays(20)]));
    $victim = $orders[2];
    $fired = 0;
    DB::listen(function ($q) use ($victim, &$fired) {
        if (str_starts_with($q->sql, 'update `orders`') && ++$fired === 1) {
            DB::table('orders')->where('id', $victim->id)->update(['status' => 'paid', 'cancelled_at' => null, 'paid_at' => now()]);
        }
    });

    Artisan::call('orders:purge-staff-notes');

    expect($fired)->toBeGreaterThan(0)
        ->and(qaStCleared($victim))->toBeFalse()
        ->and(qaStCleared($orders[0]))->toBeTrue()->and(qaStCleared($orders[3]))->toBeTrue();
});

test('refunded: paid_at cũ nhưng refunded_at mới -> giữ; refunded_at cũ -> xoá', function () {
    $recent = qaStOrder('refunded', ['paid_at' => now()->subDays(40), 'refunded_at' => now()->subDays(2)]);
    $old = qaStOrder('refunded', ['paid_at' => now()->subDays(40), 'refunded_at' => now()->subDays(9)]);

    Artisan::call('orders:purge-staff-notes');

    expect(qaStCleared($recent))->toBeFalse()->and(qaStCleared($old))->toBeTrue();
});

test('xoá tài khoản: đơn manual pending được GIỮ (status/tiền), nhưng 4 trường nhân viên nhập + order_notes.body bị xoá; ghi chú vẫn còn (tác giả, thời điểm)', function () {
    $me = User::factory()->student()->verified()->create();
    $pending = qaStOrder('pending', [], ['expires_at' => now()->addHours(10), 'created_at' => now()->subHour(), 'updated_at' => now()], $me);
    $note = DB::table('order_notes')->where('order_id', $pending->id)->first();
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now(), 'email' => null]);

    app(AccountDeletionFinalizer::class)->finalize($me->id);

    $row = DB::table('orders')->where('id', $pending->id)->first();
    $after = DB::table('order_notes')->where('order_id', $pending->id)->first();
    expect($row->status)->toBe('pending')->and($row->total_amount)->toBe($pending->total_amount)
        ->and($row->refund_note)->toBeNull()->and($row->payment_reference)->toBeNull()->and($row->cancel_reason_public)->toBeNull()
        ->and($row->customer_note)->toBeNull()
        ->and($after->id)->toBe($note->id)->and($after->body)->toBe(OrderNote::PURGED_BODY)
        ->and($after->author_id)->toBe($note->author_id)->and($after->created_at)->toBe($note->created_at);

    // Nhân viên nhập mới sau đó cho đơn pending vẫn được giữ cho tới khi đơn kết thúc + 7 ngày.
    DB::table('orders')->where('id', $pending->id)->update(['payment_reference' => 'MOI']);
    Artisan::call('orders:purge-staff-notes');
    expect(DB::table('orders')->where('id', $pending->id)->value('payment_reference'))->toBe('MOI');
});

test('admin GET /admin/orders/{code} sau purge: 200, cancel_reason null, notes[].body cố định, tác giả + thời điểm còn', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $o = qaStOrder('cancelled', ['cancelled_at' => now()->subDays(9)]);
    $note = DB::table('order_notes')->where('order_id', $o->id)->first();

    Artisan::call('orders:purge-staff-notes');

    $r = test()->getJson(vvAdminUrl("/admin/orders/{$o->code}"), vvAdminHeaders())->assertOk();
    expect($r->json('cancel_reason'))->toBeNull()
        ->and($r->json('payment_reference'))->toBeNull()
        ->and($r->json('refund_note'))->toBeNull()
        ->and($r->json('notes'))->toHaveCount(1)
        ->and($r->json('notes.0.body'))->toBe(OrderNote::PURGED_BODY)
        ->and($r->json('notes.0.author.id'))->toBe((int) $note->author_id)
        ->and($r->json('notes.0.created_at'))->not->toBeNull()
        ->and($r->json('status'))->toBe('cancelled');
});

test('học sinh GET /orders/{code} sau purge: 200, cancel_reason null, không 500', function () {
    vvMoConfig();
    $me = vvMoStudent();
    $o = qaStOrder('cancelled', ['cancelled_at' => now()->subDays(9)], [], $me);

    Artisan::call('orders:purge-staff-notes');

    $r = test()->getJson(vvApiUrl("/orders/{$o->code}"), vvWebHeaders())->assertOk();
    expect($r->json('cancel_reason'))->toBeNull()->and($r->json('status'))->toBe('cancelled')
        ->and(json_encode($r->json()))->not->toContain('0911');
});

test('duyệt muộn đơn cancelled 8 và 29 ngày tuổi (lý do đã xoá) vẫn duyệt được; can_approve_late còn true', function (int $days) {
    Mail::fake();
    vvMoConfig();
    config(['orders.manual.approval_window_days' => 30]);
    vvStaffLogin(vvStaffUser('admin'));
    $o = qaStOrder('cancelled', ['cancelled_at' => now()->subDays($days)]);
    Artisan::call('orders:purge-staff-notes');
    expect(DB::table('orders')->where('id', $o->id)->value('cancel_reason_public'))->toBeNull();

    $detail = test()->getJson(vvAdminUrl("/admin/orders/{$o->code}"), vvAdminHeaders())->assertOk();
    expect($detail->json('approval.can_approve_late'))->toBeTrue();

    vvT39Approve($o, ['late' => true])->assertOk();
    expect($o->fresh()->status->value)->toBe('paid');
})->with([8, 29]);

test('--dry-run đếm đúng và không ghi gì; --days nhỏ hơn chính sách / sai định dạng bị từ chối', function () {
    $o = qaStOrder('paid', ['paid_at' => now()->subDays(8)]);
    $before = [(array) DB::table('orders')->where('id', $o->id)->first(), DB::table('order_notes')->where('order_id', $o->id)->get()->all()];

    expect(Artisan::call('orders:purge-staff-notes', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('1 đơn')->toContain('1 ghi chú')
        ->and([(array) DB::table('orders')->where('id', $o->id)->first(), DB::table('order_notes')->where('order_id', $o->id)->get()->all()])->toEqual($before);

    foreach (['6', '1', '0', '-7', '', '7.5', 'abc', '3651'] as $bad) {
        expect(Artisan::call('orders:purge-staff-notes', ['--days' => $bad]))->toBe(1);
    }
    expect(qaStCleared($o))->toBeFalse()
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => '9']))->toBe(0)
        ->and(qaStCleared($o))->toBeFalse()
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => '7']))->toBe(0)
        ->and(qaStCleared($o))->toBeTrue();
});

test('lịch: 03:46 hằng đêm, sau 03:45, withoutOverlapping + onOneServer', function () {
    $e = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'orders:purge-staff-notes'));

    expect($e->expression)->toBe('46 3 * * *')->and($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
});

test('OrderNote vẫn chặn update/delete/save qua Eloquent kể cả sau purge', function () {
    $o = qaStOrder('paid', ['paid_at' => now()->subDays(9)]);
    Artisan::call('orders:purge-staff-notes');
    $n = OrderNote::query()->where('order_id', $o->id)->firstOrFail();

    expect(fn () => $n->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $n->delete())->toThrow(LogicException::class)
        ->and(DB::table('order_notes')->where('id', $n->id)->value('body'))->toBe(OrderNote::PURGED_BODY);
});

test('múi giờ: mốc 7 ngày chính xác đến giây ở UTC và Asia/Ho_Chi_Minh', function (string $tz) {
    $prev = date_default_timezone_get();
    config(['app.timezone' => $tz]);
    date_default_timezone_set($tz);
    try {
        test()->travelTo(Carbon::parse('2026-10-09 00:30:00', $tz));
        $exact = qaStOrder('paid', ['paid_at' => now()->subDays(7)]);
        $short = qaStOrder('paid', ['paid_at' => now()->subDays(7)->addSecond()]);
        $early7h = qaStOrder('paid', ['paid_at' => now()->subDays(7)->addHours(7)]);

        Artisan::call('orders:purge-staff-notes');

        expect(qaStCleared($exact))->toBeTrue()->and(qaStCleared($short))->toBeFalse()->and(qaStCleared($early7h))->toBeFalse();
    } finally {
        date_default_timezone_set($prev);
    }
})->with(['UTC', 'Asia/Ho_Chi_Minh']);
