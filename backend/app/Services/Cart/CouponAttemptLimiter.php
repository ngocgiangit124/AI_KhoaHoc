<?php

namespace App\Services\Cart;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * S18 — trần số lần áp mã THẤT BẠI trong 24 giờ, theo tài khoản và theo IP
 * (`config('coupon.*')`), để không dò được mã ngắn kiểu `TOAN2026`.
 *
 * KHÔNG nguyên tử nếu làm kiểu "kiểm rồi mới đếm khi sai" (N request song song
 * cùng qua bước kiểm trước khi có ai kịp đếm → vượt trần). Vì vậy đảo thứ tự
 * (cùng tinh thần `OtpService::verify()` ở T04 — tăng TRƯỚC khi so): mỗi lần
 * thử `hit()` NGAY (INCR nguyên tử của Redis), request nào ra số đếm > trần thì
 * bị chặn, TRƯỚC khi chạm DB tra mã. Nếu mã áp dụng thành công thì hoàn lại
 * lượt vừa đếm — nên chỉ lần SAI mới tiêu hao hạn mức. Trần này đứng cạnh
 * limiter theo phút của route (`throttle:coupon`), không thay thế nó.
 */
class CouponAttemptLimiter
{
    private const DECAY_SECONDS = 86400;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt  ném DomainException khi mã sai/không dùng được
     * @return T
     *
     * @throws DomainException `TOO_MANY_ATTEMPTS` (429) khi vượt trần
     */
    public function attempt(User $user, ?string $ip, Closure $attempt): mixed
    {
        $keys = [
            self::userKey($user) => (int) config('coupon.max_failed_per_day'),
        ];

        if ($ip !== null && $ip !== '') {
            $keys[self::ipKey($ip)] = (int) config('coupon.max_failed_per_day_per_ip');
        }

        $blocked = false;

        foreach ($keys as $key => $max) {
            if (RateLimiter::hit($key, self::DECAY_SECONDS) > $max) {
                $blocked = true;
            }
        }

        if ($blocked) {
            $retryAfter = max(array_map(RateLimiter::availableIn(...), array_keys($keys)));

            // Chỉ ghi audit MỘT lần mỗi lần chạm trần (tự hết hạn cùng cửa sổ 24h).
            if (Cache::add('coupon-limit-audit:'.self::userKey($user), true, self::DECAY_SECONDS)) {
                $this->audit->log('coupon.attempt_limit', $user);
            }

            throw new DomainException(
                code: 'TOO_MANY_ATTEMPTS',
                message: 'Bạn đã nhập sai mã giảm giá quá nhiều lần, vui lòng thử lại sau.',
                status: 429,
                headers: ['Retry-After' => (string) max(1, $retryAfter)],
            );
        }

        $result = $attempt();

        // Thành công (không ném): hoàn lại lượt đã đếm trước đó. Ngoại lệ (mã
        // sai, lỗi hệ thống...) → giữ nguyên lượt đã tính.
        foreach (array_keys($keys) as $key) {
            if (RateLimiter::decrement($key, self::DECAY_SECONDS) < 0) {
                RateLimiter::resetAttempts($key);
            }
        }

        return $result;
    }

    private static function userKey(User $user): string
    {
        return 'coupon-fail:user:'.$user->getKey();
    }

    private static function ipKey(string $ip): string
    {
        return 'coupon-fail:ip:'.$ip;
    }
}
