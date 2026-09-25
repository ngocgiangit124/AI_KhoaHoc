<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

test('tao user voi mang chua role=admin, email_verified_at khong duoc gan (S17)', function () {
    // Model::shouldBeStrict() đang bật ở testing (AppServiceProvider) → mọi khoá
    // ngoài $fillable (mô phỏng $request->all()) làm fill() ném MassAssignmentException
    // thay vì âm thầm bỏ qua — chặn sớm hơn cả yêu cầu tối thiểu của S17.
    expect(fn () => User::create([
        'name' => 'Kẻ tấn công',
        'email' => 'attacker@example.com',
        'phone' => '0900000001',
        'password' => 'password123',
        'role' => 'admin',
        'status' => 'active',
        'email_verified_at' => now(),
    ]))->toThrow(MassAssignmentException::class);

    expect(User::query()->where('email', 'attacker@example.com')->exists())->toBeFalse();
});

test('fillable cua User khong bao gio chua cot quyen/trang thai', function () {
    $forbidden = [
        'role', 'status', 'email_verified_at', 'phone_verified_at',
        'current_session_id', 'current_device_id', 'parent_consent_status',
        'must_change_password', 'anonymized_at',
    ];

    $fillable = (new User)->getFillable();

    foreach ($forbidden as $key) {
        expect($fillable)->not->toContain($key);
    }
});

test('User factory tao dung role mac dinh hoc sinh', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::Student);
});
