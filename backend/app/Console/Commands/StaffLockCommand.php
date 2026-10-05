<?php

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use Illuminate\Console\Command;

class StaffLockCommand extends Command
{
    protected $signature = 'staff:lock {email : Email tài khoản cần khoá}';

    protected $description = 'Khoá một tài khoản staff/giáo viên (ghi audit)';

    public function handle(StaffAccountService $service): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Không tìm thấy tài khoản: {$email}");

            return self::FAILURE;
        }

        try {
            $service->lock($user, null);
        } catch (DomainException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Đã khoá tài khoản: {$email}");

        return self::SUCCESS;
    }
}
