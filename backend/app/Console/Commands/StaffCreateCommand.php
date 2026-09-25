<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tạo tài khoản staff (admin/quản lý trang/giáo viên) — chỉ chạy trên server
 * (CLI), có ghi audit (ADR-004 §3, S15). Chạy được ở mọi môi trường, kể cả
 * production (khác với seeder demo — chỉ chạy ở local).
 */
class StaffCreateCommand extends Command
{
    protected $signature = 'staff:create {email : Email đăng nhập} {--role=quan_ly_trang : admin|quan_ly_trang|giao_vien} {--name=}';

    protected $description = 'Tạo tài khoản staff với mật khẩu ngẫu nhiên (in ra một lần), buộc đổi mật khẩu ở lần đăng nhập đầu';

    public function handle(AuditLogger $auditLogger): int
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

        $password = Str::password(20, symbols: true);

        $user = DB::transaction(function () use ($email, $role, $password) {
            // forceCreate (không phải create()) — cố ý ghi ngoài $fillable (S17):
            // command CLI là "Service chuyên trách" duy nhất được phép đổi
            // role/status trực tiếp khi tạo tài khoản staff.
            return User::query()->forceCreate([
                'name' => (string) ($this->option('name') ?: Str::before($email, '@')),
                'email' => $email,
                'role' => $role,
                'status' => UserStatus::Active,
                'password' => Hash::make($password),
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);
        });

        $auditLogger->log('staff.create', $user, [
            'role' => $role->value,
        ]);

        $this->components->info('Tạo tài khoản staff thành công.');
        $this->line("Email: {$user->email}");
        $this->line("Vai trò: {$role->value}");
        $this->warn("Mật khẩu (chỉ hiện MỘT LẦN, hãy lưu lại ngay): {$password}");

        return self::SUCCESS;
    }
}
