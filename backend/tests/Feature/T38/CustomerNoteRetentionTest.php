<?php

use App\Models\Order;
use App\Models\OrderNote;
use App\Support\ProductionConfigGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * T38-1 — `orders:purge-customer-notes`: xoá `customer_note` sau N ngày kể từ khi đơn kết thúc.
 */
beforeEach(function () {
    $this->freezeTime();
    config(['orders.manual.customer_note_retention_days' => 90]);
});

/** Tạo đơn ở trạng thái $status, kết thúc cách đây $days ngày, có lời nhắn. */
function vvCnOrder(string $status, int $days, string $note = 'Em chuyển khoản tối nay'): Order
{
    $end = now()->subDays($days);
    $col = ['paid' => 'paid_at', 'cancelled' => 'cancelled_at', 'refunded' => 'refunded_at', 'failed' => null][$status] ?? null;

    $order = Order::factory()->manual()->create([
        'status' => $status,
        'customer_note' => $note,
        'created_at' => $end->copy()->subDay(),
        'updated_at' => $end,
        'expires_at' => $end->copy()->addHour(),
        'paid_at' => $status === 'refunded' ? $end->copy()->subHour() : null,
    ]);
    if ($col !== null) {
        DB::table('orders')->where('id', $order->id)->update([$col => $end]);
    }

    return $order->fresh();
}

function vvCnNote(Order $o): ?string
{
    return DB::table('orders')->where('id', $o->id)->value('customer_note');
}

test('mốc 89/90/91 ngày cho từng trạng thái kết thúc', function (string $status) {
    $o89 = vvCnOrder($status, 89);
    $o90 = vvCnOrder($status, 90);
    $o91 = vvCnOrder($status, 91);

    expect(Artisan::call('orders:purge-customer-notes'))->toBe(0);

    expect(vvCnNote($o89))->not->toBeNull()   // chưa đủ 90 ngày
        ->and(vvCnNote($o90))->toBeNull()     // đủ 90 ngày
        ->and(vvCnNote($o91))->toBeNull();
})->with(['paid', 'cancelled', 'refunded', 'failed']);

test('đơn pending không bao giờ bị xoá, dù rất cũ', function () {
    $o = Order::factory()->manual()->create([
        'customer_note' => 'giữ',
        'created_at' => now()->subDays(400),
        'expires_at' => now()->subDays(399),
    ]);

    Artisan::call('orders:purge-customer-notes');

    expect(vvCnNote($o))->toBe('giữ');
});

test('refunded tính từ refunded_at (không phải paid_at cũ); paid sau khi huỷ tính từ paid_at', function () {
    $o = vvCnOrder('refunded', 10);   // paid_at = 10 ngày + 1 giờ trước
    DB::table('orders')->where('id', $o->id)->update(['paid_at' => now()->subDays(200)]);
    $late = vvCnOrder('paid', 5);
    DB::table('orders')->where('id', $late->id)->update(['cancelled_at' => now()->subDays(200)]);

    Artisan::call('orders:purge-customer-notes');

    expect(vvCnNote($o))->not->toBeNull()->and(vvCnNote($late))->not->toBeNull();
});

test('áp cho mọi đơn có ghi chú, kể cả không phải manual', function () {
    $o = vvCnOrder('paid', 120);
    DB::table('orders')->where('id', $o->id)->update(['payment_method' => 'momo']);

    Artisan::call('orders:purge-customer-notes');

    expect(vvCnNote($o))->toBeNull();
});

test('idempotent, chỉ đụng customer_note: cột khác, updated_at, order_notes, status logs giữ nguyên', function () {
    $o = vvCnOrder('paid', 100);
    $note = OrderNote::factory()->create(['order_id' => $o->id, 'body' => 'ghi chú nội bộ']);
    DB::table('order_status_logs')->insert(['order_id' => $o->id, 'from_status' => 'pending', 'to_status' => 'paid', 'actor_type' => 'staff', 'created_at' => now()]);

    $before = (array) DB::table('orders')->where('id', $o->id)->first();
    $logs = DB::table('order_status_logs')->where('order_id', $o->id)->count();

    Artisan::call('orders:purge-customer-notes');
    $after1 = (array) DB::table('orders')->where('id', $o->id)->first();
    Artisan::call('orders:purge-customer-notes');
    $after2 = (array) DB::table('orders')->where('id', $o->id)->first();

    expect($after1['customer_note'])->toBeNull()
        ->and(array_diff_assoc($before, $after1))->toBe(['customer_note' => 'Em chuyển khoản tối nay'])
        ->and($after2)->toBe($after1)
        ->and(DB::table('order_notes')->where('id', $note->id)->value('body'))->toBe('ghi chú nội bộ')
        ->and(DB::table('order_status_logs')->where('order_id', $o->id)->count())->toBe($logs);
});

test('xử lý nhiều lô (chunk nhỏ) và --dry-run không xoá', function () {
    config(['ops.purge_chunk' => 2, 'ops.purge_sleep_ms' => 0]);
    $orders = collect(range(1, 5))->map(fn () => vvCnOrder('paid', 100));
    $keep = vvCnOrder('paid', 10);

    Artisan::call('orders:purge-customer-notes', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('5 đơn')
        ->and($orders->every(fn ($o) => vvCnNote($o) !== null))->toBeTrue();

    Artisan::call('orders:purge-customer-notes');
    expect($orders->every(fn ($o) => vvCnNote($o) === null))->toBeTrue()->and(vvCnNote($keep))->not->toBeNull();
});

test('--days ngoài khoảng bị từ chối', function () {
    expect(Artisan::call('orders:purge-customer-notes', ['--days' => 29]))->toBe(1)
        ->and(Artisan::call('orders:purge-customer-notes', ['--days' => 'abc']))->toBe(1);
});

test('--days nhỏ hơn chính sách bị từ chối, không xoá gì; lớn hơn thì chạy', function () {
    $o = vvCnOrder('paid', 60);
    expect(Artisan::call('orders:purge-customer-notes', ['--days' => 30]))->toBe(1)
        ->and(vvCnNote($o))->not->toBeNull()
        ->and(Artisan::call('orders:purge-customer-notes', ['--days' => 120]))->toBe(0);
});

test('lệnh có trong lịch hằng đêm, withoutOverlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'orders:purge-customer-notes'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('45 3 * * *')->and($event->withoutOverlapping)->toBeTrue();
});

describe('guard production', function () {
    beforeEach(function () {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'cache.limiter' => 'redis-limiter', 'captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app',
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
        unset($_ENV['ORDERS_CUSTOMER_NOTE_RETENTION_DAYS'], $_SERVER['ORDERS_CUSTOMER_NOTE_RETENTION_DAYS']);
        putenv('ORDERS_CUSTOMER_NOTE_RETENTION_DAYS');
    });

    test('giá trị ngoài 30..3650 bị chặn, trong khoảng qua (kể cả khi cờ manual tắt)', function (int $days, bool $ok) {
        config(['orders.manual.customer_note_retention_days' => $days]);

        $ok ? expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class)
            : expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'ORDERS_CUSTOMER_NOTE_RETENTION_DAYS');
    })->with([[0, false], [29, false], [30, true], [90, true], [3650, true], [3651, false]]);

    test('chuỗi env thô không phải số nguyên bị chặn', function (string $raw) {
        $_ENV['ORDERS_CUSTOMER_NOTE_RETENTION_DAYS'] = $raw;

        expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'ORDERS_CUSTOMER_NOTE_RETENTION_DAYS');
    })->with(['abc', '90 # ghi chú', '-90', '9.5']);
});
