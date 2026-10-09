<?php

use App\Models\Order;
use App\Services\Privacy\DataExportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T34/helpers.php';

/**
 * QA T38-1 — bổ sung: mốc theo trạng thái, mốc NULL, đổi trạng thái giữa lô, múi giờ, bản xuất dữ liệu.
 */
beforeEach(function () {
    $this->freezeTime();
    config(['orders.manual.customer_note_retention_days' => 90, 'ops.purge_sleep_ms' => 0]);
});

function qaCnOrder(string $status, array $cols, ?string $note = 'lời nhắn', array $extra = []): Order
{
    $o = Order::factory()->manual()->create(array_merge([
        'status' => $status, 'customer_note' => $note,
        'created_at' => now()->subDays(300), 'updated_at' => now()->subDays(300), 'expires_at' => now()->subDays(299),
    ], $extra));
    DB::table('orders')->where('id', $o->id)->update($cols);

    return $o->fresh();
}

function qaCnNote(Order $o): ?string
{
    return DB::table('orders')->where('id', $o->id)->value('customer_note');
}

test('AC: paid rồi refunded tính từ refunded_at; refunded_at mới (10 ngày) giữ dù paid_at cũ, refunded_at cũ (95 ngày) xoá', function () {
    $recent = qaCnOrder('refunded', ['paid_at' => now()->subDays(200), 'refunded_at' => now()->subDays(10)]);
    $old = qaCnOrder('refunded', ['paid_at' => now()->subDays(200), 'refunded_at' => now()->subDays(95)]);

    Artisan::call('orders:purge-customer-notes');

    expect(qaCnNote($recent))->not->toBeNull()->and(qaCnNote($old))->toBeNull();
});

test('đơn cancelled do xoá tài khoản (note đã NULL) không lỗi và không đổi gì', function () {
    $o = qaCnOrder('cancelled', ['cancelled_at' => now()->subDays(200)], null);
    $before = (array) DB::table('orders')->where('id', $o->id)->first();

    expect(Artisan::call('orders:purge-customer-notes'))->toBe(0)
        ->and((array) DB::table('orders')->where('id', $o->id)->first())->toBe($before)
        ->and(Artisan::output())->toContain('0 đơn');
});

test('đơn kết thúc nhưng mốc NULL (lệch dữ liệu) không bị xoá', function () {
    $p = qaCnOrder('paid', ['paid_at' => null]);
    $c = qaCnOrder('cancelled', ['cancelled_at' => null]);
    $r = qaCnOrder('refunded', ['paid_at' => now()->subDays(300), 'refunded_at' => null]);

    Artisan::call('orders:purge-customer-notes');

    expect(qaCnNote($p))->not->toBeNull()->and(qaCnNote($c))->not->toBeNull()->and(qaCnNote($r))->not->toBeNull();
});

test('nhiều lô: đơn đổi trạng thái sang pending sau khi đọc id thì KHÔNG bị xoá, các đơn khác trong lô vẫn xoá', function () {
    config(['ops.purge_chunk' => 3]);
    $orders = collect(range(1, 7))->map(fn () => qaCnOrder('paid', ['paid_at' => now()->subDays(100)]));
    $victim = $orders[1];
    $fired = false;

    DB::listen(function ($q) use ($victim, &$fired) {
        if (! $fired && str_starts_with($q->sql, 'select `id` from `orders`')) {
            $fired = true;
            DB::table('orders')->where('id', $victim->id)->update(['status' => 'pending', 'paid_at' => null]);
        }
    });

    Artisan::call('orders:purge-customer-notes');

    expect($fired)->toBeTrue()
        ->and(qaCnNote($victim))->not->toBeNull()
        ->and($orders->reject(fn ($o) => $o->id === $victim->id)->every(fn ($o) => qaCnNote($o) === null))->toBeTrue();
});

test('nhiều lô: bội số đúng chunk (6 đơn, chunk 3) và chunk=1 đều xử lý hết', function (int $chunk, int $n) {
    config(['ops.purge_chunk' => $chunk]);
    $orders = collect(range(1, $n))->map(fn () => qaCnOrder('cancelled', ['cancelled_at' => now()->subDays(91)]));

    Artisan::call('orders:purge-customer-notes');

    expect($orders->every(fn ($o) => qaCnNote($o) === null))->toBeTrue()->and(Artisan::output())->toContain("{$n} đơn");
})->with([[3, 6], [1, 4], [1000, 2]]);

test('múi giờ: ranh giới 90 ngày chính xác đến giây ở UTC và Asia/Ho_Chi_Minh', function (string $tz) {
    $prev = date_default_timezone_get();
    config(['app.timezone' => $tz]);
    date_default_timezone_set($tz);
    try {
        test()->travelTo(Carbon::parse('2026-10-09 00:30:00', $tz));
        $exact = qaCnOrder('paid', ['paid_at' => now()->subDays(90)]);
        $oneSecShort = qaCnOrder('paid', ['paid_at' => now()->subDays(90)->addSecond()]);
        $sevenHoursEarly = qaCnOrder('paid', ['paid_at' => now()->subDays(90)->addHours(7)]);

        Artisan::call('orders:purge-customer-notes');

        expect(qaCnNote($exact))->toBeNull()
            ->and(qaCnNote($oneSecShort))->not->toBeNull()
            ->and(qaCnNote($sevenHoursEarly))->not->toBeNull();
    } finally {
        date_default_timezone_set($prev);
    }
})->with(['UTC', 'Asia/Ho_Chi_Minh']);

test('--dry-run không ghi gì (note, updated_at) và không ghi log purge', function () {
    $o = qaCnOrder('paid', ['paid_at' => now()->subDays(100)]);
    $before = (array) DB::table('orders')->where('id', $o->id)->first();

    expect(Artisan::call('orders:purge-customer-notes', ['--dry-run' => true]))->toBe(0)
        ->and((array) DB::table('orders')->where('id', $o->id)->first())->toBe($before)
        ->and(Artisan::output())->toContain('1 đơn');
});

test('--days bằng chính sách và lớn hơn chạy được; số âm, 0, rỗng, thập phân bị từ chối', function () {
    $o = qaCnOrder('paid', ['paid_at' => now()->subDays(100)]);

    foreach (['-90', '0', '', '9.5', '89'] as $bad) {
        expect(Artisan::call('orders:purge-customer-notes', ['--days' => $bad]))->toBe(1);
    }
    expect(qaCnNote($o))->not->toBeNull()
        ->and(Artisan::call('orders:purge-customer-notes', ['--days' => '90']))->toBe(0)
        ->and(qaCnNote($o))->toBeNull();
});

test('lịch 03:45 có withoutOverlapping và onOneServer', function () {
    $e = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'orders:purge-customer-notes'));

    expect($e->expression)->toBe('45 3 * * *')->and($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
});

test('bản xuất dữ liệu (T34) sau khi xoá: customer_note = null, đơn vẫn còn', function () {
    $me = vvT34Student();
    $o = qaCnOrder('paid', ['paid_at' => now()->subDays(100)], 'SĐT 0900000000', ['user_id' => $me->id]);

    $before = app(DataExportService::class)->build($me);
    Artisan::call('orders:purge-customer-notes');
    $after = app(DataExportService::class)->build($me);

    expect($before['orders'][0]['customer_note'])->toBe('SĐT 0900000000')
        ->and($after['orders'])->toHaveCount(1)
        ->and($after['orders'][0]['code'])->toBe($o->code)
        ->and($after['orders'][0]['customer_note'])->toBeNull();
});
