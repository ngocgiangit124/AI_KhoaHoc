<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\AtomicCounter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Xác thực lại bằng mật khẩu hiện tại cho thao tác nhạy cảm của học sinh đang đăng nhập (đổi mật khẩu, đổi liên hệ).
 *
 * Limiter route `password-change` dùng middleware của framework (kiểm rồi mới đếm, không nguyên tử): request đồng thời
 * có thể vượt trần. Vì vậy thêm ở đây bộ đếm lượt SAI nguyên tử (`AtomicCounter`, cùng mẫu `LoginService::reserveAttempts`),
 * DÙNG CHUNG cho mọi route cần mật khẩu hiện tại: dò mật khẩu qua route nào cũng tiêu cùng một hạn mức.
 */
class CurrentPasswordGuard
{
    public const MAX_FAILURES_PER_HOUR = 10;

    public const MESSAGE_WRONG = 'Mật khẩu hiện tại không đúng.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException field `current_password` khi sai
     * @throws ThrottleRequestsException quá nhiều lượt sai
     */
    public function assert(User $user, #[\SensitiveParameter] string $password, string $failureAction): void
    {
        $key = 'current-password-fail:u:'.$user->getKey();

        LoginService::reserveAttempts([[$key, self::MAX_FAILURES_PER_HOUR]]);

        if (! Hash::check($password, $user->password)) {
            $this->audit->log($failureAction, $user, ['reason' => 'wrong_current_password']);

            throw ValidationException::withMessages(['current_password' => self::MESSAGE_WRONG]);
        }

        // Chỉ đếm lượt SAI: đúng thì hoàn lượt đã giữ chỗ.
        LoginService::releaseAttempts($key);
    }
}
