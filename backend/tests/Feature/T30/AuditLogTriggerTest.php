<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** L2: trigger MySQL chặn UPDATE/DELETE `audit_logs` ở tầng DB (bypass Eloquent). */
function vvAuditRow(string $createdAt): int
{
    return (int) DB::table('audit_logs')->insertGetId(['action' => 'trigger.test', 'ip' => '1.2.3.4', 'created_at' => $createdAt]);
}

test('trigger: UPDATE audit_logs luôn lỗi, kể cả dòng cũ', function () {
    $new = vvAuditRow(now()->toDateTimeString());
    $old = vvAuditRow(now()->subMonths(30)->toDateTimeString());

    foreach ([$new, $old] as $id) {
        expect(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'hacked']))
            ->toThrow(QueryException::class, 'bat bien');
    }

    expect(DB::table('audit_logs')->where('id', $new)->value('action'))->toBe('trigger.test');
});

test('trigger: DELETE dòng mới (trong 24 tháng) lỗi; dòng cũ hơn 24 tháng xoá được', function () {
    $new = vvAuditRow(now()->toDateTimeString());
    $edge = vvAuditRow(now()->subMonths(23)->toDateTimeString());
    $old = vvAuditRow(now()->subMonths(25)->toDateTimeString());

    expect(fn () => DB::table('audit_logs')->where('id', $new)->delete())->toThrow(QueryException::class, 'bat bien');
    expect(fn () => DB::table('audit_logs')->where('id', $edge)->delete())->toThrow(QueryException::class, 'bat bien');
    expect(DB::table('audit_logs')->where('id', $old)->delete())->toBe(1);
    expect(DB::table('audit_logs')->whereIn('id', [$new, $edge])->count())->toBe(2);
});

test('trigger: DELETE không điều kiện bị chặn khi có dòng mới (không xoá được một phần)', function () {
    $new = vvAuditRow(now()->toDateTimeString());
    $old = vvAuditRow(now()->subMonths(30)->toDateTimeString());

    expect(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->whereIn('id', [$new, $old])->count())->toBe(2);
});

test('audit:purge vẫn xoá dòng quá hạn khi có trigger, giữ dòng mới', function () {
    $new = vvAuditRow(now()->toDateTimeString());
    $old = vvAuditRow(now()->subMonths(26)->toDateTimeString());

    $this->artisan('audit:purge')->assertSuccessful();

    expect(DB::table('audit_logs')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('id', $new)->exists())->toBeTrue();
});

test('audit:purge --months dưới sàn trigger (24) bị từ chối thay vì nổ lỗi DB', function () {
    vvAuditRow(now()->subMonths(5)->toDateTimeString());

    $this->artisan('audit:purge --months=12')->assertFailed();
    $this->artisan('audit:purge --months=24')->assertSuccessful();
});

test('migration trigger có down() gỡ được và up() chạy lại được', function () {
    $migration = require database_path('migrations/2026_10_16_100000_add_audit_logs_immutability_triggers.php');
    $triggers = fn () => collect(DB::select('SHOW TRIGGERS WHERE `Table` = ?', ['audit_logs']))->pluck('Trigger')->sort()->values()->all();

    expect($triggers())->toBe(['audit_logs_block_delete', 'audit_logs_block_update']);

    // DDL commit ngầm: đóng transaction của RefreshDatabase trước, mở lại sau (bảng chưa có dữ liệu của test này).
    $level = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        $migration->down();
        expect($triggers())->toBe([])
            ->and(Schema::hasTable('audit_logs'))->toBeTrue();
    } finally {
        $migration->up();
        for ($i = 0; $i < $level; $i++) {
            DB::beginTransaction();
        }
    }

    expect($triggers())->toBe(['audit_logs_block_delete', 'audit_logs_block_update']);
});
