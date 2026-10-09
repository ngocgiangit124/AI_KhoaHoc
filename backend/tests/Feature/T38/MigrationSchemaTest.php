<?php

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

function vvMoColumn(string $table, string $column): ?object
{
    return DB::selectOne('SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

function vvMoMigration(string $file): object
{
    return require database_path("migrations/{$file}.php");
}

test('orders: 3 cột mới nullable, không default, đúng kiểu; FK confirmed_by -> users restrict', function () {
    expect(vvMoColumn('orders', 'customer_note'))->toMatchObject(['type' => 'varchar(500)', 'nullable' => 'YES', 'dflt' => null]);
    expect(vvMoColumn('orders', 'cancel_reason_public'))->toMatchObject(['type' => 'varchar(500)', 'nullable' => 'YES', 'dflt' => null]);
    expect(vvMoColumn('orders', 'confirmed_by'))->toMatchObject(['type' => 'bigint unsigned', 'nullable' => 'YES', 'dflt' => null]);

    $fk = DB::selectOne("SELECT DELETE_RULE AS d, REFERENCED_TABLE_NAME AS t FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'orders_confirmed_by_foreign'");
    expect($fk->t)->toBe('users')->and($fk->d)->toBe('RESTRICT');
});

test('order_notes: cột, index (order_id,id), FK order cascade + author restrict, không updated_at; model $fillable rỗng', function () {
    expect(Schema::hasColumns('order_notes', ['id', 'order_id', 'author_id', 'body', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('order_notes', 'updated_at'))->toBeFalse()
        ->and(vvMoColumn('order_notes', 'body')->type)->toBe('varchar(1000)');

    $idx = DB::select("SELECT COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_notes' AND INDEX_NAME = 'order_notes_order_id_id_index' ORDER BY SEQ_IN_INDEX");
    expect(array_column($idx, 'c'))->toBe(['order_id', 'id']);

    $rules = collect(DB::select("SELECT COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS t FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_notes' AND REFERENCED_TABLE_NAME IS NOT NULL"))->pluck('t', 'c')->all();
    expect($rules)->toEqual(['order_id' => 'orders', 'author_id' => 'users']);

    expect((new OrderNote)->getFillable())->toBe([])->and(OrderNote::UPDATED_AT)->toBeNull();
    $note = OrderNote::factory()->create();
    expect($note->created_at)->not->toBeNull()->and($note->order->payment_method)->toBe('manual');
});

test('CHECK chk_orders_payment_method: none/manual/momo/fake/NULL qua, giá trị lạ bị DB từ chối', function () {
    foreach (['none', 'manual', 'momo', 'fake', null] as $m) {
        Order::factory()->create(['payment_method' => $m]);
    }
    expect(Order::count())->toBe(5);

    expect(fn () => Order::factory()->create(['payment_method' => 'vnpay']))->toThrow(QueryException::class);
});

test('migration CHECK: dữ liệu lạ (kể cả "MANUAL" viết hoa) -> RuntimeException, chưa ALTER; sạch -> thêm CHECK; up/down đảo ngược được', function () {
    $migration = vvMoMigration('2026_10_21_120000_add_payment_method_check_to_orders');
    $userId = User::factory()->student()->create()->id; // DDL bên dưới tự commit: dọn tay ở finally
    $orderIds = [];

    try {
        $migration->down(); // DROP CHECK (INPLACE)
        $has = fn () => DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'chk_orders_payment_method'")->n;
        expect($has())->toBe(0);

        foreach (['MANUAL', 'vnpay'] as $i => $bad) {
            $orderIds[] = DB::table('orders')->insertGetId([
                'code' => 'VVMIG'.$i.strtoupper(bin2hex(random_bytes(3))), 'user_id' => $userId, 'status' => 'cancelled', 'subtotal_amount' => 0, 'discount_amount' => 0,
                'total_amount' => 0, 'payment_method' => $bad, 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'MANUAL');
        expect($has())->toBe(0); // chưa ALTER

        DB::table('orders')->whereIn('id', $orderIds)->delete();
        $orderIds = [];
        $migration->up();
        expect($has())->toBe(1);
    } finally {
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('users')->where('id', $userId)->delete();
        if (DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'chk_orders_payment_method'")->n === 0) {
            $migration->up();
        }
    }
});

test('migration 1 và 2: down() rồi up() đảo ngược được', function () {
    $m1 = vvMoMigration('2026_10_21_100000_add_manual_payment_columns_to_orders');
    $m2 = vvMoMigration('2026_10_21_110000_create_order_notes_table');
    $m3 = vvMoMigration('2026_10_21_120000_add_payment_method_check_to_orders');

    try {
        $m3->down();
        $m2->down();
        $m1->down();
        expect(Schema::hasColumn('orders', 'customer_note'))->toBeFalse()->and(Schema::hasColumn('orders', 'confirmed_by'))->toBeFalse()->and(Schema::hasTable('order_notes'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('orders', 'customer_note')) {
            $m1->up();
        }
        if (! Schema::hasTable('order_notes')) {
            $m2->up();
        }
        $m3->up();
    }

    expect(Schema::hasColumns('orders', ['customer_note', 'cancel_reason_public', 'confirmed_by']))->toBeTrue()->and(Schema::hasTable('order_notes'))->toBeTrue();
});
