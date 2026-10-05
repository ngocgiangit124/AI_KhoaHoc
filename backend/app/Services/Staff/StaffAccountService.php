<?php

namespace App\Services\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Staff\StaffSessionRevoker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quản lý tài khoản staff (US-016, T33) — dùng chung cho API admin và lệnh `staff:create|lock|unlock`.
 *
 * `$actor = null` nghĩa là chạy từ CLI (không có người thao tác): bỏ qua kiểm "tự thao tác chính mình", nhưng vẫn
 * giữ quy tắc "luôn còn ≥ 1 admin đang hoạt động". Mọi thao tác ghi audit trong CÙNG transaction; không bao giờ
 * ghi mật khẩu vào audit. Huỷ phiên (`StaffSessionRevoker`) chạy sau khi commit.
 */
class StaffAccountService
{
    /** Ba vai trò staff được tạo/đổi qua màn quản lý (không có học sinh). */
    public const ROLES = [UserRole::Admin, UserRole::PageManager, UserRole::Teacher];

    public const PASSWORD_LENGTH = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{user: User, password: string} mật khẩu ngẫu nhiên chỉ trả MỘT lần
     *
     * @throws ValidationException email đã tồn tại (kể cả khi thua race unique)
     */
    public function create(?User $actor, string $name, string $email, UserRole $role): array
    {
        $email = mb_strtolower(trim($email));

        if ($role === UserRole::Student) {
            throw ValidationException::withMessages(['role' => 'Vai trò không hợp lệ.']);
        }

        $password = Str::password(self::PASSWORD_LENGTH, symbols: true);

        try {
            $user = DB::transaction(function () use ($name, $email, $role, $password): User {
                // forceCreate: Service chuyên trách duy nhất được ghi role/status ngoài $fillable (S17).
                $user = User::query()->forceCreate([
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'status' => UserStatus::Active,
                    'password' => Hash::make($password),
                    'must_change_password' => true,
                    'email_verified_at' => now(),
                ]);

                $this->audit->log('staff.create', $user, ['role' => $role->value]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email đã được sử dụng.']);
        }

        return ['user' => $user, 'password' => $password];
    }

    public function lock(User $target, ?User $actor): User
    {
        $user = DB::transaction(function () use ($target, $actor): User {
            $locked = $this->lockTarget($target, $actor, 'khoá');

            if ($locked->status === UserStatus::Locked) {
                throw $this->alreadyProcessed('Tài khoản đã bị khoá.');
            }

            $this->guardLastAdmin($locked, 'khoá');

            $locked->forceFill(['status' => UserStatus::Locked])->save();
            $this->audit->log('user.lock', $locked, [
                'status' => ['from' => UserStatus::Active->value, 'to' => UserStatus::Locked->value],
            ]);

            return $locked;
        });

        StaffSessionRevoker::revokeAll($user->getKey());

        return $user;
    }

    public function unlock(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $locked = $this->lockTarget($target, null, 'mở khoá');

            if ($locked->status === UserStatus::Active) {
                throw $this->alreadyProcessed('Tài khoản đang hoạt động.');
            }

            $locked->forceFill(['status' => UserStatus::Active])->save();
            $this->audit->log('user.unlock', $locked, [
                'status' => ['from' => UserStatus::Locked->value, 'to' => UserStatus::Active->value],
            ]);

            return $locked;
        });
    }

    /**
     * Đổi vai trò giữa 3 vai trò staff; huỷ phiên để quyền mới có hiệu lực từ lần đăng nhập sau (và MFA áp theo
     * vai trò mới).
     */
    public function changeRole(User $target, UserRole $role, ?User $actor): User
    {
        if ($role === UserRole::Student) {
            throw ValidationException::withMessages(['role' => 'Vai trò không hợp lệ.']);
        }

        $user = DB::transaction(function () use ($target, $role, $actor): User {
            $locked = $this->lockTarget($target, $actor, 'đổi vai trò');

            if ($locked->role === $role) {
                throw $this->alreadyProcessed('Tài khoản đã có vai trò này.');
            }

            if ($role !== UserRole::Admin) {
                $this->guardLastAdmin($locked, 'hạ quyền');
            }

            $from = $locked->role;
            $locked->forceFill(['role' => $role])->save();
            $this->audit->log('staff.role_change', $locked, [
                'role' => ['from' => $from->value, 'to' => $role->value],
            ]);

            return $locked;
        });

        StaffSessionRevoker::revokeAll($user->getKey());

        return $user;
    }

    /**
     * Đặt lại mật khẩu: sinh mật khẩu mới, buộc đổi ở lần đăng nhập sau, huỷ mọi phiên (băm đổi + phiên bản huỷ).
     *
     * @return array{user: User, password: string}
     */
    public function resetPassword(User $target, ?User $actor): array
    {
        $password = Str::password(self::PASSWORD_LENGTH, symbols: true);

        $user = DB::transaction(function () use ($target, $actor, $password): User {
            $locked = $this->lockTarget($target, $actor, 'đặt lại mật khẩu');

            $locked->forceFill([
                'password' => $password, // cast `hashed`
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();
            $this->audit->log('staff.password_reset', $locked);

            return $locked;
        });

        StaffSessionRevoker::revokeAll($user->getKey());

        return ['user' => $user, 'password' => $password];
    }

    /**
     * Khoá hàng admin (theo id tăng dần — tránh deadlock khi 2 admin thao tác chéo nhau) rồi khoá hàng đích.
     *
     * @throws DomainException CANNOT_MODIFY_SELF
     */
    private function lockTarget(User $target, ?User $actor, string $verb): User
    {
        if ($actor !== null && $actor->getKey() === $target->getKey()) {
            throw new DomainException(
                code: 'CANNOT_MODIFY_SELF',
                message: "Bạn không thể tự {$verb} tài khoản của chính mình.",
                status: 409,
            );
        }

        User::query()->where('role', UserRole::Admin->value)->orderBy('id')->lockForUpdate()->get(['id']);

        $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

        // Chỉ tài khoản staff (CLI nhận email bất kỳ nên phải chặn ở đây, không chỉ ở route).
        if (! in_array($locked->role, self::ROLES, true)) {
            throw new DomainException(
                code: 'NOT_STAFF',
                message: 'Chỉ thao tác được trên tài khoản staff (admin, quản lý trang, giáo viên).',
                status: 422,
            );
        }

        return $locked;
    }

    /** Chặn thao tác làm mất admin đang hoạt động cuối cùng (BR6). Gọi sau `lockTarget`. */
    private function guardLastAdmin(User $locked, string $verb): void
    {
        if ($locked->role !== UserRole::Admin || $locked->status !== UserStatus::Active) {
            return;
        }

        $others = User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($locked->getKey())
            ->exists();

        if (! $others) {
            throw new DomainException(
                code: 'LAST_ADMIN',
                message: "Không thể {$verb} tài khoản quản trị viên đang hoạt động cuối cùng.",
                status: 409,
            );
        }
    }

    private function alreadyProcessed(string $message): DomainException
    {
        return new DomainException(code: 'ALREADY_PROCESSED', message: $message, status: 409);
    }
}
