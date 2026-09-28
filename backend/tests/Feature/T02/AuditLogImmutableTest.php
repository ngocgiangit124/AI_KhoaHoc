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

/**
 * M5 (review bảo mật T01/T02) — `$log->action = 'x'; $log->save();` KHÔNG đi
 * qua method `update()` (bị override ở trên): `save()` trên bản ghi đã tồn
 * tại gọi thẳng `performUpdate()`. Chặn bằng sự kiện `saving`.
 */
test('AuditLog khong the sua qua save() truc tiep (bypass update())', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    $log->action = 'hacked';

    expect(fn () => $log->save())->toThrow(LogicException::class);
});

/**
 * M5 — `AuditLog::where(...)->update()/delete()` đi qua Eloquent Builder trực
 * tiếp, KHÔNG qua `$model->update()/delete()` hay sự kiện `saving`/`deleting`
 * (chỉ fire trên từng model instance). Chặn bằng `ImmutableAuditLogBuilder`.
 */
test('AuditLog khong the sua qua query builder update()', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->where('id', $log->id)->update(['action' => 'hacked']))
        ->toThrow(LogicException::class);
});

test('AuditLog khong the xoa qua query builder delete()', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->where('id', $log->id)->delete())
        ->toThrow(LogicException::class);

    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

/**
 * M5 (xác minh lại review bảo mật T01/T02) — các đường bypass khác đã đối
 * chiếu mã nguồn Laravel 13: `saveQuietly()` tắt hẳn sự kiện `saving`;
 * `increment()`/`decrement()` trên instance chỉ bắn `updating` (không bắn
 * `saving`); `AuditLog::query()->increment()/decrement()/incrementEach()/
 * decrementEach()/touch()/upsert()/forceDelete()/truncate()` đi thẳng qua
 * Eloquent Builder, không qua sự kiện model nào.
 */
test('AuditLog khong the sua qua saveQuietly()', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    $log->action = 'hacked';

    expect(fn () => $log->saveQuietly())->toThrow(LogicException::class);
});

test('AuditLog khong the tang/giam qua increment()/decrement() tren instance', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => $log->increment('subject_id'))->toThrow(LogicException::class);
    expect(fn () => $log->decrement('subject_id'))->toThrow(LogicException::class);
});

test('AuditLog khong the tang/giam qua query builder increment()/decrement()/incrementEach()/decrementEach()', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->where('id', $log->id)->increment('subject_id'))
        ->toThrow(LogicException::class);
    expect(fn () => AuditLog::query()->where('id', $log->id)->decrement('subject_id'))
        ->toThrow(LogicException::class);
    expect(fn () => AuditLog::query()->where('id', $log->id)->incrementEach(['subject_id' => 1]))
        ->toThrow(LogicException::class);
    expect(fn () => AuditLog::query()->where('id', $log->id)->decrementEach(['subject_id' => 1]))
        ->toThrow(LogicException::class);
});

test('AuditLog khong the touch() qua query builder', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->where('id', $log->id)->touch())
        ->toThrow(LogicException::class);
});

test('AuditLog khong the upsert() qua query builder', function () {
    app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->upsert(
        [['action' => 'hacked', 'created_at' => now()]],
        ['id']
    ))->toThrow(LogicException::class);
});

test('AuditLog khong the forceDelete() qua query builder', function () {
    $log = app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->where('id', $log->id)->forceDelete())
        ->toThrow(LogicException::class);

    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

test('AuditLog khong the truncate() qua query builder', function () {
    app(AuditLogger::class)->log('user.lock', null, ['status' => 'locked']);

    expect(fn () => AuditLog::query()->truncate())->toThrow(LogicException::class);

    expect(AuditLog::query()->count())->toBeGreaterThan(0);
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

/**
 * L4 (review bảo mật T01/T02) — trước đây `str_contains('code')` lọc luôn
 * `coupon_code`/`referral_code_used` (mất dữ liệu audit hợp lệ, vd T15 mã
 * giảm giá). Danh sách khoá CHÍNH XÁC vẫn chặn đúng field `code` (mã OTP nhập
 * vào form) mà không đụng tới các khoá nghiệp vụ chỉ CHỨA chữ "code".
 */
test('AuditLogger khong loc nham coupon_code/referral_code_used nhung van chan code', function () {
    app(AuditLogger::class)->log('coupon.create', null, [
        'coupon_code' => 'TOAN2026',
        'referral_code_used' => 'REF123',
        'code' => '654321',
        'discount_value' => 50,
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('coupon_code')
        ->and($changes)->toHaveKey('referral_code_used')
        ->and($changes)->toHaveKey('discount_value')
        ->and($changes)->not->toHaveKey('code');
});

test('AuditLogger loc them secret/otp/address/*_key (L4)', function () {
    app(AuditLogger::class)->log('config.update', null, [
        'secret' => 'x',
        'otp' => '123456',
        'address' => '123 Lê Lợi',
        'access_key' => 'AK123',
        'api_token' => 'abc',
        'note' => 'Ghi chú hợp lệ',
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('note')
        ->and($changes)->not->toHaveKey('secret')
        ->and($changes)->not->toHaveKey('otp')
        ->and($changes)->not->toHaveKey('address')
        ->and($changes)->not->toHaveKey('access_key')
        ->and($changes)->not->toHaveKey('api_token');
});

/**
 * N1 (review bảo mật T01/T02, hồi quy từ L4) — chuyển `password`/`secret`/
 * `otp`/`token` sang so khớp CHÍNH XÁC (thay vì "chứa chuỗi") đã làm lọt các
 * khoá ghép tên rất phổ biến ở T03/T04 (đăng ký, OTP, đổi mật khẩu):
 * `new_password`, `current_password`, `password_confirmation`, `otp_code`,
 * `verification_code`. Đưa các khoá này về so khớp "chứa chuỗi" trở lại +
 * thêm hậu tố `_code`, vẫn giữ allowlist cho `coupon_code`/`referral_code_used`.
 */
test('AuditLogger khong con lot cac khoa ghep ten mat khau/otp/secret (N1)', function () {
    app(AuditLogger::class)->log('auth.update', null, [
        'new_password' => 'x',
        'current_password' => 'y',
        'password_confirmation' => 'z',
        'password_hash' => 'hash',
        'otp_code' => '123456',
        'verification_code' => '654321',
        'reset_code' => '111111',
        'client_secret_value' => 'abc',
        'access_token' => 'token-value',
        'name' => 'Học sinh A',
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('name')
        ->and($changes)->not->toHaveKey('new_password')
        ->and($changes)->not->toHaveKey('current_password')
        ->and($changes)->not->toHaveKey('password_confirmation')
        ->and($changes)->not->toHaveKey('password_hash')
        ->and($changes)->not->toHaveKey('otp_code')
        ->and($changes)->not->toHaveKey('verification_code')
        ->and($changes)->not->toHaveKey('reset_code')
        ->and($changes)->not->toHaveKey('client_secret_value')
        ->and($changes)->not->toHaveKey('access_token');
});

test('AuditLogger van giu coupon_code/referral_code_used sau khi them hau to _code (N1)', function () {
    app(AuditLogger::class)->log('coupon.create', null, [
        'coupon_code' => 'TOAN2026',
        'referral_code_used' => 'REF123',
        'verification_code' => 'should-be-filtered',
    ]);

    $log = AuditLog::query()->latest('id')->first();
    $changes = $log->changes;

    expect($changes)->toHaveKey('coupon_code')
        ->and($changes)->toHaveKey('referral_code_used')
        ->and($changes)->not->toHaveKey('verification_code');
});

test('AuditLogger ghi actor_role=cli khi chay tu console va khong co actor dang nhap (L4)', function () {
    $log = app(AuditLogger::class)->log('staff.create', null, ['role' => 'admin']);

    // Pest chay qua CLI (php vendor/bin/pest) nen app()->runningInConsole() = true
    // ngay trong test — đúng bối cảnh thực tế của lệnh `staff:create` chạy nền.
    expect($log->actor_id)->toBeNull();
    expect($log->actor_role)->toBe('cli');
});
