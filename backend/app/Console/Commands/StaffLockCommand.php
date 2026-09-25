<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class StaffLockCommand extends Command
{
    protected $signature = 'staff:lock {email : Email tài khoản cần khoá}';

    protected $description = 'Khoá một tài khoản staff/giáo viên (ghi audit)';

    public function handle(AuditLogger $auditLogger): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Không tìm thấy tài khoản: {$email}");

            return self::FAILURE;
        }

        $user->forceFill(['status' => UserStatus::Locked])->save();

        $auditLogger->log('user.lock', $user);

        $this->components->info("Đã khoá tài khoản: {$email}");

        return self::SUCCESS;
    }
}
