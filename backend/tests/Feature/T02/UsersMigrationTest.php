<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('CHECK chk_users_grade_level chan gia tri ngoai 6..12', function () {
    $violates = false;

    try {
        DB::table('users')->insert([
            'name' => 'Invalid Grade',
            'email' => 'invalid-grade@example.com',
            'phone' => '0900000099',
            'password' => bcrypt('password'),
            'grade_level' => 13,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $violates = str_contains($e->getMessage(), 'chk_users_grade_level') || str_contains($e->getMessage(), '3819');
    }

    expect($violates)->toBeTrue();
});

test('CHECK chk_users_grade_level chan gia tri duoi 6', function () {
    $violates = false;

    try {
        DB::table('users')->insert([
            'name' => 'Invalid Grade Low',
            'email' => 'invalid-grade-low@example.com',
            'phone' => '0900000097',
            'password' => bcrypt('password'),
            'grade_level' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $violates = str_contains($e->getMessage(), 'chk_users_grade_level') || str_contains($e->getMessage(), '3819');
    }

    expect($violates)->toBeTrue();
});

test('CHECK chk_users_grade_level cho phep 2 gia tri bien 6 va 12', function () {
    $idLow = DB::table('users')->insertGetId([
        'name' => 'Grade Six',
        'email' => 'grade-six@example.com',
        'phone' => '0900000096',
        'password' => bcrypt('password'),
        'grade_level' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $idHigh = DB::table('users')->insertGetId([
        'name' => 'Grade Twelve',
        'email' => 'grade-twelve@example.com',
        'phone' => '0900000095',
        'password' => bcrypt('password'),
        'grade_level' => 12,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('users')->where('id', $idLow)->value('grade_level'))->toBe(6);
    expect(DB::table('users')->where('id', $idHigh)->value('grade_level'))->toBe(12);
});

test('grade_level NULL van hop le (staff khong co lop)', function () {
    $id = DB::table('users')->insertGetId([
        'name' => 'Staff No Grade',
        'email' => 'staff-no-grade@example.com',
        'phone' => '0900000098',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'grade_level' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('users')->where('id', $id)->value('grade_level'))->toBeNull();
});
