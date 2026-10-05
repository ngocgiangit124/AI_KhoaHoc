<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Http\Requests\Admin\Staff\StaffStoreRequest;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tạo tài khoản staff (admin/quản lý trang/giáo viên) — chỉ chạy trên server
 * (CLI), có ghi audit (ADR-004 §3, S15). Chạy được ở mọi môi trường, kể cả
 * production (khác với seeder demo — chỉ chạy ở local).
 */
class StaffCreateCommand extends Command
{
    protected $signature = 'staff:create {email : Email đăng nhập} {--role=quan_ly_trang : admin|quan_ly_trang|giao_vien} {--name=}';

    protected $description = 'Tạo tài khoản staff với mật khẩu ngẫu nhiên (in ra một lần), buộc đổi mật khẩu ở lần đăng nhập đầu';

    public function handle(StaffAccountService $service): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $roleOption = (string) $this->option('role');
        $role = UserRole::tryFrom($roleOption);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("Email không hợp lệ: {$email}");

            return self::FAILURE;
        }

        if ($role === null || $role === UserRole::Student) {
            $this->components->error('--role phải là admin, quan_ly_trang hoặc giao_vien.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("Email đã tồn tại: {$email}");

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $request = new StaffStoreRequest;
        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role->value],
            $request->rules(),
            $request->messages(),
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->all()[0]);

            return self::FAILURE;
        }

        try {
            ['user' => $user, 'password' => $password] = $service->create(
                null,
                $name,
                $email,
                $role,
            );
        } catch (ValidationException $e) {
            $this->components->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->components->info('Tạo tài khoản staff thành công.');
        $this->line("Email: {$user->email}");
        $this->line("Vai trò: {$role->value}");
        $this->warn("Mật khẩu (chỉ hiện MỘT LẦN, hãy lưu lại ngay): {$password}");

        return self::SUCCESS;
    }
}
