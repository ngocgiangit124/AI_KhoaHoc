<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpSenderManager;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sinh/gửi/xác thực mã OTP (US-001 AC8/AC9, api-contract §2.2, data-model
 * §3.1 — S9). Mã KHÔNG BAO GIỜ được lưu ở dạng rõ hay ghi log (S21) — chỉ tồn
 * tại trong biến cục bộ đủ lâu để hash và gửi đi.
 *
 * S9 — `verify()` tăng `attempts` NGUYÊN TỬ (UPDATE có điều kiện) TRƯỚC khi so
 * mã: 0 dòng ảnh hưởng (hết hạn/đã tiêu thụ/hết lượt) coi như sai, không chạy
 * `Hash::check()`.
 */
class OtpService
{
    public function __construct(private readonly OtpSenderManager $senders) {}

    /**
     * Sinh mã mới cho (user, purpose, channel). Huỷ MỌI mã còn hiệu lực của
     * (user, purpose) — KHÔNG lọc theo `channel` (T04 review R6): trước đây
     * chỉ huỷ đúng cùng kênh, nên nếu có 2 mã active khác kênh cho cùng
     * purpose (vd đổi cả email lẫn SĐT trong 1 request), `verify()` chỉ xét
     * mã mới nhất theo `id` — mã còn lại tuy vẫn "hợp lệ" trong DB nhưng
     * không bao giờ so khớp được, gây báo sai "mã không đúng". Đảm bảo tại
     * mọi thời điểm CHỈ có tối đa 1 mã active cho mỗi (user, purpose) giúp
     * `verify()` không bao giờ phải chọn giữa nhiều mã. Đánh đổi: nếu 1
     * request đổi CẢ email lẫn SĐT (hiếm, chỉ khả thi khi kênh `sms` được
     * bật — local/testing), mã của kênh gửi trước sẽ bị mã của kênh gửi sau
     * huỷ ngay; chấp nhận vì production MVP chỉ có kênh `email`.
     *
     * @return Carbon Thời điểm được phép bấm "Gửi lại mã" (`resend_available_at`).
     *
     * @throws DomainException `TOO_MANY_ATTEMPTS` (429) khi vượt trần gửi
     *                         (cooldown/giờ/ngày/đích — S9, T04 review R1/M2/L1).
     */
    public function send(User $user, OtpPurpose $purpose, string $channel): Carbon
    {
        $this->assertChannelAllowed($channel);

        $created = $this->createCodeAtomically($user, $purpose, $channel, null, invalidateAllPurposes: false);

        foreach ($created as $c) {
            $this->senders->forChannel($c['channel'])->send($c['destination'], $c['code']);
        }

        return now()->addSeconds((int) config('auth.otp.cooldown_seconds'));
    }

    /**
     * Như `send()`, nhưng bỏ qua (không ném lỗi) khi kênh không được bật —
     * dùng khi việc gửi là hệ quả PHỤ của 1 thao tác khác (vd đổi SĐT trong
     * lúc production chỉ bật kênh `email`), không phải hành động chính người
     * dùng vừa yêu cầu.
     */
    public function sendIfChannelEnabled(User $user, OtpPurpose $purpose, string $channel): ?Carbon
    {
        if (! in_array($channel, (array) config('auth.otp.channels'), true)) {
            return null;
        }

        return $this->send($user, $purpose, $channel);
    }

    /**
     * T04 review R1 — cho phép caller kiểm trần gửi TRƯỚC khi thực hiện thay
     * đổi khác, dùng như 1 "fast-path" báo lỗi sớm KHÔNG cần giữ khoá hàng
     * user (khác `sendAfterContactChange()` — nguồn sự thật nguyên tử thật sự
     * cho `ContactService`, xem M2). Không làm gì (không ném lỗi) nếu kênh
     * chưa được bật.
     *
     * @throws DomainException `TOO_MANY_ATTEMPTS` (429).
     */
    public function assertCanSend(User $user, string $channel): void
    {
        if (! in_array($channel, (array) config('auth.otp.channels'), true)) {
            return;
        }

        $this->assertUnderSendLimits($user);
    }

    /**
     * T04 review M1+M2 — dùng bởi `ContactService::update()`: đổi liên hệ
     * (qua `$applyContactChange`) + kiểm trần gửi + huỷ MỌI mã cũ (mọi
     * purpose/channel — M1, tránh mã của kênh/đích CŨ còn sống sau khi đổi) +
     * tạo mã mới cho từng kênh đã đổi và được bật — TẤT CẢ trong 1
     * transaction có khoá hàng `users` (M2 — không còn cửa sổ "đã đổi liên hệ
     * nhưng gửi mã thất bại/không huỷ được mã cũ" dưới tải đồng thời: vượt
     * trần thì `$applyContactChange` KHÔNG được chạy, liên hệ KHÔNG đổi).
     * Gửi mail SAU khi transaction đã commit.
     *
     * @param  Closure(): void  $applyContactChange  Gán `$user->email`/`$user->phone`
     *                                               (KHÔNG cần gọi `save()` — hàm này tự lưu).
     * @param  list<string>  $channels  Kênh cần gửi mã mới (vd `['email']`) — kênh
     *                                  chưa được bật (`auth.otp.channels`) sẽ tự bị bỏ qua.
     *
     * @throws DomainException `TOO_MANY_ATTEMPTS` (429) — liên hệ KHÔNG bị đổi.
     * @throws QueryException Vi phạm unique `users.email`/`phone`
     *                        khi `$applyContactChange` đổi liên hệ
     *                        trùng (race 2 request đổi liên hệ cùng
     *                        lúc) — `ContactService` dịch thành 422.
     */
    public function sendAfterContactChange(User $user, OtpPurpose $purpose, Closure $applyContactChange, array $channels): void
    {
        $enabledChannels = array_values(array_intersect($channels, (array) config('auth.otp.channels')));

        $created = $this->createCodeAtomically($user, $purpose, $enabledChannels, $applyContactChange, invalidateAllPurposes: true);

        foreach ($created as $c) {
            $this->senders->forChannel($c['channel'])->send($c['destination'], $c['code']);
        }
    }

    /**
     * @throws ValidationException Mã sai/hết hạn — field `code` (422).
     */
    public function verify(User $user, OtpPurpose $purpose, string $code): void
    {
        $otp = OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw self::invalidCodeException();
        }

        // T04 security review M1 — thêm `whereNull('invalidated_at')`: giữa
        // lúc SELECT ở trên và UPDATE này, mã có thể vừa bị 1 request khác
        // huỷ (vd `sendAfterContactChange()` đang đổi liên hệ) — trước đây
        // UPDATE vẫn tăng được `attempts` cho 1 mã đã invalidated vì thiếu
        // điều kiện này, tạo khe hở nhỏ để mã "chết" vẫn được so tiếp.
        //
        // S9 — tăng attempts NGUYÊN TỬ bằng UPDATE có điều kiện TRƯỚC khi so
        // mã: 0 dòng ảnh hưởng (hết hạn/đã tiêu thụ/hết lượt/đã huỷ) = coi
        // như sai.
        $affected = OtpCode::query()
            ->where('id', $otp->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', (int) config('auth.otp.max_attempts_per_code'))
            ->update(['attempts' => DB::raw('attempts + 1')]);

        if ($affected === 0) {
            throw $otp->expires_at->isPast() ? self::expiredCodeException() : self::invalidCodeException();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            throw self::invalidCodeException();
        }

        $consumed = OtpCode::query()
            ->where('id', $otp->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            // Race hiếm: 1 request khác đã tiêu thụ HOẶC huỷ đúng mã này giữa
            // lúc so hash và lúc consume.
            throw self::invalidCodeException();
        }

        // T04 security review M1 [Medium] — "mã gắn với đích" (data-model
        // §3.1): TRƯỚC ĐÂY không so `destination` của mã với liên hệ HIỆN TẠI
        // của user, nên nếu (do lỗi khác hoặc race) có mã còn sống nhưng
        // `destination` không còn khớp với `email`/`phone` hiện tại (vd email
        // đã đổi sau khi mã được tạo), người giữ mã CŨ (đích cũ) vẫn xác thực
        // được cho đích MỚI mà họ không sở hữu. Kiểm NGAY TRƯỚC KHI đánh dấu
        // đã xác thực — đã tiêu thụ mã ở bước trên nên dù từ chối ở đây, mã
        // này cũng không dùng lại được nữa (không mở thêm oracle nào).
        // `hash_equals` không cần thiết về mặt thời gian (không so sánh với bí
        // mật), nhưng an toàn khi so 2 chuỗi do người dùng ảnh hưởng.
        $current = $otp->channel === 'email' ? $user->email : $user->phone;

        if ($current === null || ! hash_equals(mb_strtolower($otp->destination), mb_strtolower($current))) {
            throw self::invalidCodeException();
        }

        self::markVerified($user, $otp->channel);
    }

    private static function markVerified(User $user, string $channel): void
    {
        $column = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';

        // Không nằm trong $fillable (S17) — chỉ Service chuyên trách được đổi.
        $user->forceFill([$column => now()])->save();
    }

    private function assertChannelAllowed(string $channel): void
    {
        if (! in_array($channel, (array) config('auth.otp.channels'), true)) {
            throw new RuntimeException("Kênh OTP '{$channel}' không được bật (auth.otp.channels).");
        }
    }

    /**
     * T04 security review M2 [Medium] — lõi nguyên tử dùng chung bởi
     * `send()` và `sendAfterContactChange()`.
     *
     * TRƯỚC ĐÂY `assertUnderSendLimits()` (đếm bằng `count()`) chạy TRƯỚC 1
     * transaction RIÊNG tạo `otp_codes`: đây vẫn là "check-then-act" — N
     * request tới gần như đồng thời cho CÙNG 1 user đều đọc thấy "chưa vượt
     * trần" (đếm ra cùng 1 con số) trước khi bất kỳ request nào kịp INSERT,
     * nên cả N đều vượt qua, y hệt lỗ hổng ở tầng `ThrottleRequests` mà
     * review trước đó đã chỉ ra (kiểm HẾT các limit rồi mới `hit()`).
     *
     * Sửa bằng cách khoá HÀNG `users` tương ứng (`lockForUpdate`) ngay khi
     * vào transaction: transaction thứ 2 trở đi cho CÙNG user phải ĐỢI tới
     * khi transaction đầu tiên COMMIT (đã ghi `otp_codes` mới) mới được đọc
     * tiếp — nên `assertUnderSendLimits()` chạy SAU BƯỚC KHOÁ này luôn thấy
     * đúng số liệu mới nhất, không còn cửa sổ race. Gửi mail (I/O mạng/queue)
     * luôn xảy ra SAU KHI transaction đã commit (không giữ khoá DB trong lúc
     * chờ I/O) — do đó hàm này CHỈ trả dữ liệu để caller tự gửi ở ngoài.
     *
     * @param  string|list<string>  $channels  1 kênh (chuỗi, dùng bởi `send()`)
     *                                         hoặc nhiều kênh (dùng bởi
     *                                         `sendAfterContactChange()`).
     * @param  Closure(): void|null  $beforeCreate  Chạy SAU khi đã giữ khoá + kiểm trần,
     *                                              TRƯỚC khi tạo mã — dùng để đổi
     *                                              email/SĐT nguyên tử cùng lúc.
     * @return list<array{channel: string, destination: string, code: string}>
     *
     * @throws QueryException Vi phạm unique `users.email`/`phone`
     *                        khi `$beforeCreate` đổi liên hệ trùng
     *                        (race 2 request đổi liên hệ cùng lúc) —
     *                        caller (`ContactService`) dịch thành 422.
     */
    private function createCodeAtomically(User $user, OtpPurpose $purpose, string|array $channels, ?Closure $beforeCreate, bool $invalidateAllPurposes): array
    {
        $channelList = is_array($channels) ? $channels : [$channels];

        return DB::transaction(function () use ($user, $purpose, $channelList, $beforeCreate, $invalidateAllPurposes): array {
            // M2 — khoá hàng user để TUẦN TỰ HOÁ các request gửi OTP đồng
            // thời của CÙNG 1 user. `lockForUpdate()` trên chính bản ghi
            // `$user` (không phải bảng `otp_codes`) vì đây là tài nguyên DUY
            // NHẤT luôn tồn tại sẵn cho mọi user, kể cả user chưa từng có
            // `otp_codes` nào (không có hàng nào để khoá nếu khoá theo
            // otp_codes).
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($channelList !== []) {
                $this->assertUnderSendLimits($user);

                foreach ($channelList as $channel) {
                    self::assertUnderDestinationLimit(self::destinationFor($user, $channel));
                }
            }

            if ($beforeCreate !== null) {
                $beforeCreate();
                $user->save();
            }

            if ($invalidateAllPurposes || $channelList !== []) {
                $invalidateQuery = OtpCode::query()
                    ->where('user_id', $user->getKey())
                    ->whereNull('consumed_at')
                    ->whereNull('invalidated_at');

                if (! $invalidateAllPurposes) {
                    $invalidateQuery->where('purpose', $purpose->value);
                }

                $invalidateQuery->update(['invalidated_at' => now()]);
            }

            $created = [];

            foreach ($channelList as $channel) {
                $destination = self::destinationFor($user, $channel);
                $code = self::generateCode();

                OtpCode::query()->create([
                    'user_id' => $user->getKey(),
                    'purpose' => $purpose->value,
                    'channel' => $channel,
                    'destination' => $destination,
                    'code_hash' => Hash::make($code),
                    'expires_at' => now()->addMinutes((int) config('auth.otp.ttl_minutes')),
                ]);

                self::hitDestinationLimit($destination);

                $created[] = ['channel' => $channel, 'destination' => $destination, 'code' => $code];
            }

            return $created;
        });
    }

    /**
     * T04 review R1 [BLOCKER], M2 [Medium] — trần gửi (cooldown 60s, ≤5/giờ,
     * ≤10/ngày — S9, api-contract §1.6) đếm số `otp_codes` THẬT trong DB (độc
     * lập với route/middleware nào gọi tới) — PHẢI được gọi SAU khi đã giữ
     * khoá hàng user (`createCodeAtomically()`), nếu không quay lại đúng lỗ
     * hổng M2.
     */
    private function assertUnderSendLimits(User $user): void
    {
        $userId = $user->getKey();
        $now = now();

        $cooldownSeconds = (int) config('auth.otp.cooldown_seconds');
        $latestOtp = OtpCode::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->first(['created_at']);

        if ($latestOtp !== null) {
            $cooldownEndsAt = $latestOtp->created_at->copy()->addSeconds($cooldownSeconds);

            if ($cooldownEndsAt->isFuture()) {
                throw self::tooManySendException($now->diffInSeconds($cooldownEndsAt));
            }
        }

        $maxPerHour = (int) config('auth.otp.max_per_hour');
        $hourWindowStart = $now->copy()->subHour();
        $sentLastHour = OtpCode::query()->where('user_id', $userId)->where('created_at', '>=', $hourWindowStart)->count();

        if ($sentLastHour >= $maxPerHour) {
            $oldest = OtpCode::query()->where('user_id', $userId)->where('created_at', '>=', $hourWindowStart)->oldest('created_at')->first(['created_at']);
            throw self::tooManySendException($oldest !== null ? $now->diffInSeconds($oldest->created_at->copy()->addHour()) : 3600);
        }

        $maxPerDay = (int) config('auth.otp.max_per_day');
        $dayWindowStart = $now->copy()->subDay();
        $sentLastDay = OtpCode::query()->where('user_id', $userId)->where('created_at', '>=', $dayWindowStart)->count();

        if ($sentLastDay >= $maxPerDay) {
            $oldest = OtpCode::query()->where('user_id', $userId)->where('created_at', '>=', $dayWindowStart)->oldest('created_at')->first(['created_at']);
            throw self::tooManySendException($oldest !== null ? $now->diffInSeconds($oldest->created_at->copy()->addDay()) : 86400);
        }
    }

    /**
     * T04 security review L1 [Low] — trần theo ĐỊA CHỈ NHẬN (không chỉ theo
     * user): unique trên `users.email`/`phone` chỉ đảm bảo tại 1 THỜI ĐIỂM có
     * đúng 1 user giữ 1 địa chỉ, nhưng nhiều user LẦN LƯỢT đổi sang CÙNG 1
     * địa chỉ CHƯA đăng ký (mỗi user tốn 1 lần captcha lúc đăng ký) vẫn có
     * thể dồn nhiều mã tới cùng 1 hộp thư/SĐT theo thời gian. Dùng
     * `RateLimiter` (đếm nguyên tử qua Redis, không cần thêm cột/migration)
     * thay vì đếm `otp_codes.destination` bằng DB — khoá theo
     * `sha256(lowercase(destination))`, KHÔNG lưu địa chỉ rõ trong khoá cache.
     * Đây là lớp phòng thủ bổ sung (Low, chấp nhận check-then-hit không hoàn
     * toàn nguyên tử — cùng mức đảm bảo với `throttle:otp-send`/`otp-verify`
     * hiện có ở tầng route, không phải lớp chính chống race của M2).
     */
    private static function assertUnderDestinationLimit(string $destination): void
    {
        $hashedKey = self::destinationLimiterKey($destination);
        $maxPerHour = (int) config('auth.otp.max_per_hour_per_destination');
        $maxPerDay = (int) config('auth.otp.max_per_day_per_destination');

        if (RateLimiter::tooManyAttempts($hashedKey.':hour', $maxPerHour)
            || RateLimiter::tooManyAttempts($hashedKey.':day', $maxPerDay)) {
            $retryAfter = max(
                RateLimiter::availableIn($hashedKey.':hour'),
                RateLimiter::availableIn($hashedKey.':day'),
            );

            throw self::tooManySendException($retryAfter);
        }
    }

    private static function hitDestinationLimit(string $destination): void
    {
        $hashedKey = self::destinationLimiterKey($destination);

        RateLimiter::hit($hashedKey.':hour', 3600);
        RateLimiter::hit($hashedKey.':day', 86400);
    }

    private static function destinationLimiterKey(string $destination): string
    {
        return 'otp-dest:'.hash('sha256', mb_strtolower($destination));
    }

    /**
     * @param  int|float  $retryAfterSeconds  `Carbon::diffInSeconds()` có thể trả `float`.
     */
    private static function tooManySendException(int|float $retryAfterSeconds): DomainException
    {
        return new DomainException(
            code: 'TOO_MANY_ATTEMPTS',
            message: 'Bạn gửi mã quá nhanh, vui lòng thử lại sau.',
            status: 429,
            // T04 security review L3 — 429 ném từ tầng Service (không đi qua
            // middleware `throttle:`) trước đây KHÔNG có `Retry-After`, khác
            // hành vi 429 của route. `max(1, ...)` vì `diffInSeconds` có thể
            // ra 0 (hoặc âm do độ trễ tính toán) ngay tại thời điểm vừa hết hạn.
            headers: ['Retry-After' => (string) max(1, (int) round($retryAfterSeconds))],
        );
    }

    private static function destinationFor(User $user, string $channel): string
    {
        $destination = $channel === 'email' ? $user->email : $user->phone;

        if ($destination === null || $destination === '') {
            throw new RuntimeException("Tài khoản không có thông tin liên hệ cho kênh '{$channel}'.");
        }

        return $destination;
    }

    /**
     * CSPRNG — không dùng `mt_rand`/`rand` (S9).
     */
    private static function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private static function invalidCodeException(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => ['Mã OTP không đúng, vui lòng thử lại.'],
        ]);
    }

    private static function expiredCodeException(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => ["Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới."],
        ]);
    }
}
