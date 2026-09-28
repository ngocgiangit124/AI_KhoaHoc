<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class StaffUnlockCommand extends Command
{
    protected $signature = 'staff:unlock {email : Email tài khoản cần mở khoá}';

    protected $description = 'Mở khoá một tài khoản staff/giáo viên (ghi audit)';

    public function handle(AuditLogger $auditLogger): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Không tìm thấy tài khoản: {$email}");

            return self::FAILURE;
        }

        $from = $user->status;

        $user->forceFill(['status' => UserStatus::Active])->save();

        // L4 (review bảo mật T01/T02) — ghi rõ giá trị trước/sau thay vì `changes` rỗng.
        $auditLogger->log('user.unlock', $user, [
            'status' => ['from' => $from->value, 'to' => UserStatus::Active->value],
        ]);

        $this->components->info("Đã mở khoá tài khoản: {$email}");

        return self::SUCCESS;
    }
}
