<?php

use App\Console\Commands\PurgeOrderStaffNotes;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Services\Privacy\AccountDeletionFinalizer;
use App\Support\ProductionConfigGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

/**
 * T38-2 — `orders:purge-staff-notes`: xoá nội dung nhân viên tự nhập sau N ngày kể từ khi đơn kết thúc.
 */
beforeEach(function () {
    $this->freezeTime();
    config(['orders.manual.staff_text_retention_days' => 7]);
});

/** Đơn ở trạng thái $status, kết thúc cách đây $days ngày, có đủ 3 trường văn bản + 1 ghi chú nội bộ. */
function vvStOrder(string $status, int $days): Order
{
    $end = now()->subDays($days);
    $col = ['paid' => 'paid_at', 'cancelled' => 'cancelled_at', 'refunded' => 'refunded_at', 'failed' => null][$status] ?? null;

    $order = Order::factory()->manual()->create([
        'status' => $status,
        'customer_note' => 'giữ nguyên lời nhắn',
        'refund_note' => 'Hoàn theo yêu cầu của phụ huynh 0911222333',
        'payment_reference' => 'CK-123456',
        'cancel_reason_public' => 'Lý do huỷ',
        'created_at' => $end->copy()->subDay(),
        'updated_at' => $end,
        'expires_at' => $end->copy()->addHour(),
        'paid_at' => $status === 'refunded' ? $end->copy()->subHour() : null,
    ]);
    if ($col !== null) {
        DB::table('orders')->where('id', $order->id)->update([$col => $end]);
    }
    OrderNote::factory()->create(['order_id' => $order->id, 'body' => 'Đã gọi 0911222333']);

    return $order->fresh();
}

function vvStPurged(Order $o): bool
{
    $row = DB::table('orders')->where('id', $o->id)->first();
    $body = DB::table('order_notes')->where('order_id', $o->id)->value('body');
    $orderCleared = $row->refund_note === null && $row->payment_reference === null && $row->cancel_reason_public === null;
    $notCleared = $row->refund_note !== null && $row->payment_reference !== null && $row->cancel_reason_public !== null;
    $noteCleared = $body === PurgeOrderStaffNotes::PLACEHOLDER;

    expect($orderCleared || $notCleared)->toBeTrue()->and($orderCleared === $noteCleared)->toBeTrue();

    return $orderCleared;
}

test('mốc 6/7/8 ngày cho từng trạng thái kết thúc', function (string $status) {
    $o6 = vvStOrder($status, 6);
    $o7 = vvStOrder($status, 7);
    $o8 = vvStOrder($status, 8);

    expect(Artisan::call('orders:purge-staff-notes'))->toBe(0);

    expect(vvStPurged($o6))->toBeFalse()->and(vvStPurged($o7))->toBeTrue()->and(vvStPurged($o8))->toBeTrue();
})->with(['paid', 'cancelled', 'refunded', 'failed']);

test('đơn pending không bao giờ bị đụng, dù rất cũ', function () {
    $o = Order::factory()->manual()->create(['payment_reference' => 'X', 'created_at' => now()->subDays(400), 'expires_at' => now()->subDays(399)]);
    OrderNote::factory()->create(['order_id' => $o->id, 'body' => 'giữ']);

    Artisan::call('orders:purge-staff-notes');

    expect(DB::table('orders')->where('id', $o->id)->value('payment_reference'))->toBe('X')
        ->and(DB::table('order_notes')->where('order_id', $o->id)->value('body'))->toBe('giữ');
});

test('refunded tính từ refunded_at, không phải paid_at cũ', function () {
    $o = vvStOrder('refunded', 2);
    DB::table('orders')->where('id', $o->id)->update(['paid_at' => now()->subDays(200)]);

    Artisan::call('orders:purge-staff-notes');

    expect(vvStPurged($o))->toBeFalse();
});

test('đơn chỉ có ghi chú nội bộ (các trường khác NULL) vẫn bị xoá body; đơn không có gì thì không đếm', function () {
    $o = vvStOrder('paid', 30);
    DB::table('orders')->where('id', $o->id)->update(['refund_note' => null, 'payment_reference' => null, 'cancel_reason_public' => null]);

    expect(Artisan::call('orders:purge-staff-notes'))->toBe(0);
    expect(DB::table('order_notes')->where('order_id', $o->id)->value('body'))->toBe(PurgeOrderStaffNotes::PLACEHOLDER);

    Artisan::call('orders:purge-staff-notes');
    expect(Artisan::output())->toContain('0 đơn')->toContain('0 ghi chú');
});

test('idempotent; chỉ đổi đúng 4 trường: cột khác, updated_at, customer_note, status logs, audit giữ nguyên', function () {
    $o = vvStOrder('paid', 20);
    $admin = User::factory()->admin()->create();
    DB::table('order_status_logs')->insert(['order_id' => $o->id, 'from_status' => 'pending', 'to_status' => 'paid', 'actor_type' => 'staff', 'created_at' => now()]);
    DB::table('audit_logs')->insert(['actor_id' => $admin->id, 'action' => 'order.manual_approve', 'subject_type' => 'order', 'subject_id' => $o->id, 'created_at' => now()]);

    $before = (array) DB::table('orders')->where('id', $o->id)->first();
    $logs = DB::table('order_status_logs')->where('order_id', $o->id)->get()->toArray();
    $audit = DB::table('audit_logs')->where('subject_id', $o->id)->get()->toArray();
    $noteBefore = (array) DB::table('order_notes')->where('order_id', $o->id)->first();

    Artisan::call('orders:purge-staff-notes');
    $after1 = (array) DB::table('orders')->where('id', $o->id)->first();
    Artisan::call('orders:purge-staff-notes');
    $after2 = (array) DB::table('orders')->where('id', $o->id)->first();
    $noteAfter = (array) DB::table('order_notes')->where('order_id', $o->id)->first();

    expect(array_keys(array_diff_assoc($before, $after1)))->toEqualCanonicalizing(['refund_note', 'payment_reference', 'cancel_reason_public'])
        ->and($after2)->toBe($after1)
        ->and(array_keys(array_diff_assoc($noteBefore, $noteAfter)))->toBe(['body'])
        ->and($noteAfter['created_at'])->toBe($noteBefore['created_at'])
        ->and($noteAfter['author_id'])->toBe($noteBefore['author_id'])
        ->and(DB::table('order_status_logs')->where('order_id', $o->id)->get()->toArray())->toEqual($logs)
        ->and(DB::table('audit_logs')->where('subject_id', $o->id)->get()->toArray())->toEqual($audit);
});

test('nhiều lô (chunk nhỏ) và --dry-run không xoá', function () {
    config(['ops.purge_chunk' => 2, 'ops.purge_sleep_ms' => 0]);
    $orders = collect(range(1, 5))->map(fn () => vvStOrder('paid', 10));
    $keep = vvStOrder('paid', 1);

    Artisan::call('orders:purge-staff-notes', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('5 đơn')->toContain('5 ghi chú')
        ->and($orders->every(fn ($o) => ! vvStPurged($o)))->toBeTrue();

    Artisan::call('orders:purge-staff-notes');
    expect($orders->every(fn ($o) => vvStPurged($o)))->toBeTrue()->and(vvStPurged($keep))->toBeFalse();
});

test('--days sai hoặc nhỏ hơn chính sách bị từ chối; lớn hơn thì chạy', function () {
    config(['orders.manual.staff_text_retention_days' => 7]);
    $o = vvStOrder('paid', 8);

    expect(Artisan::call('orders:purge-staff-notes', ['--days' => 0]))->toBe(1)
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => 'abc']))->toBe(1)
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => 3]))->toBe(1)
        ->and(vvStPurged($o))->toBeFalse()
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => 10]))->toBe(0)
        ->and(vvStPurged($o))->toBeFalse()
        ->and(Artisan::call('orders:purge-staff-notes', ['--days' => 8]))->toBe(0)
        ->and(vvStPurged($o))->toBeTrue();
});

test('OrderNote vẫn chặn sửa/xoá qua Eloquent', function () {
    $note = OrderNote::factory()->create();

    expect(fn () => $note->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
});

test('lệnh có trong lịch hằng đêm, withoutOverlapping, onOneServer', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'orders:purge-staff-notes'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('46 3 * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});

test('xoá tài khoản (pha B) xoá cả 4 trường nhân viên nhập trên MỌI đơn của HS, order_notes thành chuỗi cố định; đơn người khác giữ', function () {
    vvMoConfig();
    Mail::fake();
    $me = User::factory()->student()->verified()->create();
    $paid = vvMoOrder($me, null, ['status' => 'paid', 'paid_at' => now(), 'refund_note' => 'ghi', 'payment_reference' => 'CK1', 'cancel_reason_public' => 'lý do'], 'manual');
    $other = vvMoOrder(User::factory()->student()->verified()->create(), null, ['payment_reference' => 'CK2']);
    OrderNote::factory()->create(['order_id' => $paid->id, 'body' => 'SĐT 0911']);
    OrderNote::factory()->create(['order_id' => $other->id, 'body' => 'của người khác']);
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now(), 'email' => null]);

    app(AccountDeletionFinalizer::class)->finalize($me->id);
    app(AccountDeletionFinalizer::class)->finalize($me->id);

    $row = DB::table('orders')->where('id', $paid->id)->first();
    expect($row->refund_note)->toBeNull()->and($row->payment_reference)->toBeNull()->and($row->cancel_reason_public)->toBeNull()
        ->and($row->status)->toBe('paid')->and($row->total_amount)->toBe(100000)
        ->and(DB::table('order_notes')->where('order_id', $paid->id)->value('body'))->toBe(PurgeOrderStaffNotes::PLACEHOLDER)
        ->and(DB::table('orders')->where('id', $other->id)->value('payment_reference'))->toBe('CK2')
        ->and(DB::table('order_notes')->where('order_id', $other->id)->value('body'))->toBe('của người khác');
});

describe('guard production', function () {
    beforeEach(function () {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'captcha.driver' => 'turnstile',
            'auth.otp.channels' => ['email'], 'auth.otp.e2e_relaxed' => false,
            'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net',
            'app.url' => 'https://api.vitaminvui.vn', 'app.frontend_url' => 'https://vitaminvui.vn', 'app.admin_url' => 'https://admin.vitaminvui.vn',
            'app.trusted_proxies' => '10.0.0.1,10.0.0.2', 'payments.enabled_gateways' => ['momo'],
            'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/v2/gateway/api/create',
            'payments.gateways.momo.pay_url_hosts' => ['payment.momo.vn'],
            'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
            'internal.required' => true, 'internal.ssr_token' => str_repeat('a', 64), 'internal.ssr_token_min_length' => 32,
            'features.paid_checkout' => false, 'features.manual_payment' => false,
            'videolab.enabled' => true, 'videolab.api_key' => str_repeat('a', 32), 'videolab.token_key' => str_repeat('b', 32), 'videolab.webhook_secret' => str_repeat('c', 32),
        ]);
    });

    afterEach(function () {
        unset($_ENV['ORDERS_STAFF_TEXT_RETENTION_DAYS'], $_SERVER['ORDERS_STAFF_TEXT_RETENTION_DAYS']);
        putenv('ORDERS_STAFF_TEXT_RETENTION_DAYS');
    });

    test('giá trị ngoài 1..3650 bị chặn, trong khoảng qua (kể cả khi cờ manual tắt)', function (int $days, bool $ok) {
        config(['orders.manual.staff_text_retention_days' => $days]);

        $ok ? expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class)
            : expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'ORDERS_STAFF_TEXT_RETENTION_DAYS');
    })->with([[0, false], [1, true], [7, true], [3650, true], [3651, false]]);

    test('chuỗi env thô không phải số nguyên bị chặn', function (string $raw) {
        $_ENV['ORDERS_STAFF_TEXT_RETENTION_DAYS'] = $raw;

        expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'ORDERS_STAFF_TEXT_RETENTION_DAYS');
    })->with(['abc', '7 # ghi chú', '-7', '7.5']);
});
