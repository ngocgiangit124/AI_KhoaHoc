<?php

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;

test('AuditLog la bat bien khong the update hoac delete', function () {
    app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    $log = AuditLog::query()->first();

    expect($log)->not->toBeNull();
    expect(fn () => $log->update(['action' => 'hacked']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

test('AuditLogger loc PII/secret khoi changes', function () {
    app(AuditLogger::class)->log('course.update', null, [
        'password' => 'should-not-be-here',
        'parent_phone' => '0900000000',
        'email' => 'x@example.com',
        'signature' => 'abc',
        'title' => 'Khóa học Toán',
        'nested' => ['token' => 'secret-token', 'ok' => true],
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('title')
        ->and($changes)->not->toHaveKey('password')
        ->and($changes)->not->toHaveKey('parent_phone')
        ->and($changes)->not->toHaveKey('email')
        ->and($changes)->not->toHaveKey('signature')
        ->and($changes['nested'])->not->toHaveKey('token')
        ->and($changes['nested'])->toHaveKey('ok');
});

test('AuditLogger loc PII/secret khong phan biet hoa/thuong', function () {
    app(AuditLogger::class)->log('user.update', null, [
        'Password' => 'x',
        'PARENT_PHONE' => '0900000000',
        'Email' => 'x@example.com',
        'Token' => 'abc',
        'Code' => '123456',
        'Name' => 'Học sinh A',
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('Name')
        ->and($changes)->not->toHaveKey('Password')
        ->and($changes)->not->toHaveKey('PARENT_PHONE')
        ->and($changes)->not->toHaveKey('Email')
        ->and($changes)->not->toHaveKey('Token')
        ->and($changes)->not->toHaveKey('Code');
});

test('AuditLogger loc PII/secret o do sau bat ky (long nhieu tang)', function () {
    app(AuditLogger::class)->log('order.update', null, [
        'order' => [
            'customer' => [
                'contact' => [
                    'parent_email' => 'phuhuynh@example.com',
                    'password' => 'should-not-leak',
                    'note' => 'Ghi chú hợp lệ',
                ],
            ],
        ],
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $contact = $log->changes['order']['customer']['contact'];

    expect($contact)->toHaveKey('note')
        ->and($contact)->not->toHaveKey('parent_email')
        ->and($contact)->not->toHaveKey('password');
});

test('AuditLogger giu nguyen changes rong khi khong co du lieu nhay cam', function () {
    $log = app(AuditLogger::class)->log('subject.create', null, ['name' => 'Toán']);

    expect($log->changes)->toBe(['name' => 'Toán']);
    expect($log->actor_id)->toBeNull(); // Không có user đăng nhập trong test này (hệ thống).
});
