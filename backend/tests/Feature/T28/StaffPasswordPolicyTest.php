<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\NotCommonPassword;
use App\Services\Staff\StaffAccountService;
use App\Support\StaffPassword;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;

require_once __DIR__.'/helpers.php';

/**
 * M1 (review bảo mật cụm 1): staff >= 12 ký tự, chặn danh sách mật khẩu phổ biến CỤC BỘ (không gọi HIBP).
 */
function vvStaffChange(string $new): TestResponse
{
    return test()->putJson(vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'password', 'password' => $new, 'password_confirmation' => $new,
    ], vvAdminHeaders());
}

test('staff đổi mật khẩu: ngắn dưới 12 hoặc phổ biến -> 422 field password', function (string $weak) {
    config(['features.staff_mfa' => false]);
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);

    vvStaffChange($weak)->assertStatus(422)->assertJsonValidationErrors('password');
    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
})->with([
    '8 số' => '12345678',
    'phổ biến 11 ký tự' => 'password123',
    'phổ biến hoa thường' => 'PassWord123',
    '11 ký tự ngẫu nhiên' => 'Xk9#mQ2$vL7',
    'phổ biến dài' => 'qwertyuiop123',
]);

test('staff đổi mật khẩu: không chứa phần trước @ của email', function () {
    config(['features.staff_mfa' => false]);
    $admin = vvStaffUser('admin', ['email' => 'quantri.vien@example.com']);
    vvStaffLogin($admin);

    vvStaffChange('Quantri.Vien-2026-x')->assertStatus(422)->assertJsonValidationErrors('password');
});

test('staff đổi mật khẩu: 14 ký tự ngẫu nhiên -> 200', function () {
    config(['features.staff_mfa' => false]);
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);

    vvStaffChange('t7Vq-9xLmZ4rBw')->assertOk();
    expect(Hash::check('t7Vq-9xLmZ4rBw', $admin->fresh()->password))->toBeTrue();
});

test('mật khẩu hệ thống sinh cho staff đủ dài và qua chính sách staff', function () {
    expect(StaffAccountService::PASSWORD_LENGTH)->toBeGreaterThanOrEqual(StaffPassword::MIN_LENGTH);

    $service = app(StaffAccountService::class);
    $created = $service->create(null, 'Nhân sự', 'nhansu.moi@example.com', UserRole::Teacher);
    $reset = $service->resetPassword($created['user'], User::factory()->admin()->create());

    foreach ([$created['password'], $reset['password']] as $generated) {
        expect(mb_strlen($generated))->toBeGreaterThanOrEqual(StaffPassword::MIN_LENGTH);
        $v = Validator::make(['password' => $generated], ['password' => StaffPassword::rules('nhansu.moi@example.com')]);
        expect($v->passes())->toBeTrue();
    }
});

test('staff:create in mật khẩu đủ dài, qua chính sách staff', function () {
    $this->artisan('staff:create', ['email' => 'cli.staff@example.com', '--role' => 'giao_vien'])
        ->expectsOutputToContain('Mật khẩu')->assertSuccessful();
    expect(User::where('email', 'cli.staff@example.com')->exists())->toBeTrue();
});

test('danh sách phổ biến: cục bộ, không phân biệt hoa/thường, không chặn mật khẩu ngẫu nhiên', function () {
    expect(NotCommonPassword::isCommon('12345678'))->toBeTrue()
        ->and(NotCommonPassword::isCommon('PASSWORD123'))->toBeTrue()
        ->and(NotCommonPassword::isCommon('matkhau123'))->toBeTrue()
        ->and(NotCommonPassword::isCommon('t7Vq-9xLmZ4rBw'))->toBeFalse();

    $lines = file(resource_path('data/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(count($lines))->toBeGreaterThan(10000);
});

test('không dùng uncompromised() (HIBP ở nước ngoài) ở bất kỳ đâu trong app', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            expect(Str::contains(file_get_contents($file->getPathname()), 'uncompromised('))->toBeFalse($file->getPathname());
        }
    }
});

test('học sinh: min 8 giữ nguyên nhưng chặn mật khẩu phổ biến (đăng ký, đặt lại, đổi)', function () {
    $rules = ['password' => ['required', 'string', Password::defaults()]];

    foreach (['12345678', 'password123', 'Matkhau123', 'abc12345'] as $weak) {
        expect(Validator::make(['password' => $weak], $rules)->fails())->toBeTrue($weak);
    }
    expect(Validator::make(['password' => 'short7!'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['password' => 'Xk9mQ2vL'], $rules)->passes())->toBeTrue();
});

test('UserFactory: mật khẩu mặc định `password` chỉ ở testing; môi trường khác không tạo user bằng factory', function () {
    expect(UserFactory::defaultPlainPassword())->toBe('password');

    app()->detectEnvironment(fn () => 'local');
    expect(UserFactory::defaultPlainPassword())->toBe(config('auth.demo_password'))
        ->and(Validator::make(['p' => config('auth.demo_password')], ['p' => StaffPassword::rules()])->passes())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => UserFactory::defaultPlainPassword())->toThrow(RuntimeException::class);
});
