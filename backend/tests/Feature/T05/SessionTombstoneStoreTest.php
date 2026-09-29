<?php

use App\Support\SessionTombstoneStore;

test('put roi get tra dung payload', function () {
    SessionTombstoneStore::put('phien-abc', [
        'reason' => 'replaced',
        'new_device_id' => '550e8400-e29b-41d4-a716-446655440000',
        'at' => '2026-01-01T00:00:00+07:00',
    ]);

    expect(SessionTombstoneStore::get('phien-abc'))->toBe([
        'reason' => 'replaced',
        'new_device_id' => '550e8400-e29b-41d4-a716-446655440000',
        'at' => '2026-01-01T00:00:00+07:00',
    ]);
});

test('get tra null khi chua tung ghi', function () {
    expect(SessionTombstoneStore::get('khong-ton-tai'))->toBeNull();
});

test('key la sha256 co tien to co dinh, khong lo session id tho', function () {
    $key = SessionTombstoneStore::key('phien-bat-ky');

    expect($key)->toStartWith('session_replaced:');
    expect($key)->not->toContain('phien-bat-ky');
    expect($key)->toBe('session_replaced:'.hash('sha256', 'phien-bat-ky'));
});

test('new_device_id co the null (thu hoi vi doi mat khau/khoa tai khoan)', function () {
    SessionTombstoneStore::put('phien-xyz', [
        'reason' => 'password_changed',
        'new_device_id' => null,
        'at' => '2026-01-01T00:00:00+07:00',
    ]);

    expect(SessionTombstoneStore::get('phien-xyz')['new_device_id'])->toBeNull();
});
