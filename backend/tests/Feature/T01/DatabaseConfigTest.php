<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('transaction isolation la READ-COMMITTED (DBA #1)', function () {
    $row = DB::selectOne('SELECT @@transaction_isolation AS iso');

    expect($row->iso)->toBe('READ-COMMITTED');
});

test('collation utf8mb4_0900_ai_ci bo qua dau khi so sanh unique (DBA checklist §5.7)', function () {
    DB::statement('DROP TEMPORARY TABLE IF EXISTS t01_collation_check');
    DB::statement(
        'CREATE TEMPORARY TABLE t01_collation_check ('
        .'name VARCHAR(100) COLLATE utf8mb4_0900_ai_ci NOT NULL, '
        .'UNIQUE KEY uq_name (name)'
        .') ENGINE=InnoDB'
    );

    DB::table('t01_collation_check')->insert(['name' => 'Hình học']);

    // "Hinh hoc" (không dấu) va "Hình học" (co dau) coi la TRUNG theo
    // utf8mb4_0900_ai_ci (accent-insensitive) — ghi lai hanh vi thuc te (DBA #7).
    $duplicateThrown = false;

    try {
        DB::table('t01_collation_check')->insert(['name' => 'Hinh hoc']);
    } catch (QueryException $e) {
        $duplicateThrown = str_contains($e->getMessage(), '1062');
    }

    expect($duplicateThrown)->toBeTrue();

    DB::statement('DROP TEMPORARY TABLE IF EXISTS t01_collation_check');
});

test('Dai so vs Đại số cung coi la trung theo collation', function () {
    DB::statement('DROP TEMPORARY TABLE IF EXISTS t01_collation_check_2');
    DB::statement(
        'CREATE TEMPORARY TABLE t01_collation_check_2 ('
        .'name VARCHAR(100) COLLATE utf8mb4_0900_ai_ci NOT NULL, '
        .'UNIQUE KEY uq_name (name)'
        .') ENGINE=InnoDB'
    );

    DB::table('t01_collation_check_2')->insert(['name' => 'Đại số']);

    $duplicateThrown = false;

    try {
        DB::table('t01_collation_check_2')->insert(['name' => 'Dai so']);
    } catch (QueryException $e) {
        $duplicateThrown = str_contains($e->getMessage(), '1062');
    }

    expect($duplicateThrown)->toBeTrue();

    DB::statement('DROP TEMPORARY TABLE IF EXISTS t01_collation_check_2');
});
