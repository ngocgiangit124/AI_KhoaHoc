<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('parent_phone va parent_email luu ciphertext trong DB (S7)', function () {
    $user = User::factory()->minor()->create();

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->parent_phone)->not->toBeNull();
    expect($raw->parent_phone)->not->toBe($user->getRawOriginal('parent_phone') === null ? '' : $user->parent_phone);
    // Ciphertext của cast `encrypted` là JSON base64 (iv/value/mac), không chứa số điện thoại gốc.
    expect($raw->parent_phone)->not->toContain(substr((string) $user->parent_phone, 0, 6));
    expect($raw->parent_email)->not->toContain(explode('@', (string) $user->parent_email)[0]);

    // Đọc lại qua Eloquent (cast tự giải mã) phải ra đúng giá trị gốc.
    $fresh = User::query()->find($user->id);
    expect($fresh->parent_phone)->toBe($user->parent_phone);
});

test('parent_phone va parent_email khong xuat hien trong toArray/toJson (hidden)', function () {
    $user = User::factory()->minor()->create();

    $array = $user->toArray();

    expect($array)->not->toHaveKey('parent_phone');
    expect($array)->not->toHaveKey('parent_email');
    expect($array)->not->toHaveKey('password');
    expect($array)->not->toHaveKey('current_session_id');
});
