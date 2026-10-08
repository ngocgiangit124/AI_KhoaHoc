<?php

namespace App\Services\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Exceptions\OtpValidationException;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Privacy\ParentNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * OTP (US-001, S9, S21). Quy tắc bắt buộc:
 * - Mã sinh bằng `random_int` (CSPRNG), lưu `Hash::make`, KHÔNG log mã rõ.
 * - Verify: tăng `attempts` nguyên tử TRƯỚC khi so; consume bằng UPDATE có điều kiện.
 * - Gửi: cooldown 60s, ≤ 5/giờ, ≤ 10/ngày/user — đếm từ chính bảng `otp_codes` (nguồn sự thật,
 *   không phụ thuộc cache) dưới khoá hàng `users` nên request song song không vượt trần.
 */
class OtpService
{
    public const MESSAGE_WRONG = 'Mã OTP không đúng, vui lòng thử lại.';

    public const MESSAGE_EXPIRED = "Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới.";

    public const MESSAGE_ALREADY_VERIFIED = 'Tài khoản đã được xác thực.';

    public function __construct(
        private readonly OtpSender $sender,
        private readonly AuditLogger $audit,
        private readonly ParentNotifier $parentNotifier,
    ) {}

    /**
     * Gửi OTP xác thực tài khoản tới email/SĐT hiện tại của user.
     *
     * @return CarbonImmutable thời điểm được phép gửi lại (`resend_available_at`)
     *
     * @throws ValidationException kênh không được phép, hoặc đích đã xác thực
     * @throws ThrottleRequestsException vượt cooldown/giờ/ngày
     */
    public function sendVerification(User $user, string $channel, bool $enforceCooldown = true): CarbonImmutable
    {
        if (! in_array($channel, (array) config('auth.otp.channels'), true)) {
            throw ValidationException::withMessages(['channel' => 'Kênh gửi mã không được hỗ trợ.']);
        }

        $destination = $channel === 'sms' ? $user->phone : $user->email;
        $alreadyVerified = $channel === 'sms' ? $user->phone_verified_at !== null : $user->email_verified_at !== null;

        if ($destination === null || $destination === '') {
            throw ValidationException::withMessages(['channel' => 'Tài khoản chưa có thông tin liên hệ cho kênh này.']);
        }

        if ($alreadyVerified) {
            throw ValidationException::withMessages(['channel' => 'Thông tin liên hệ này đã được xác thực.']);
        }

        return $this->issue($user, OtpPurpose::VerifyAccount, $channel, $destination, $enforceCooldown);
    }

    /**
     * Phát mã mới: huỷ mã còn hiệu lực cùng purpose, kiểm trần, lưu hash, gửi sau commit.
     *
     * @return CarbonImmutable thời điểm được phép gửi lại
     */
    public function issue(User $user, OtpPurpose $purpose, string $channel, string $destination, bool $enforceCooldown = true): CarbonImmutable
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cooldown = (int) config('auth.otp.cooldown_seconds');

        /** @var array{at: CarbonImmutable, id: int}|array{retry: int, audit: bool} $result */
        $result = DB::transaction(function () use ($user, $purpose, $channel, $destination, $code, $enforceCooldown, $cooldown): array {
            // Khoá hàng user: tuần tự hoá mọi lần phát mã của cùng 1 tài khoản.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $blocked = $this->sendLimitViolation($user, $enforceCooldown, $cooldown);

            if ($blocked !== null) {
                return $blocked;
            }

            OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $created = OtpCode::create([
                'user_id' => $user->getKey(),
                'purpose' => $purpose,
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('auth.otp.ttl_minutes')),
            ]);

            return ['at' => CarbonImmutable::now(), 'id' => (int) $created->getKey()];
        });

        if (! isset($result['at'])) {
            // Ghi audit NGOÀI transaction (transaction đã kết thúc, không bị rollback cùng).
            if ($result['audit']) {
                $this->audit->log('otp.send_limit_reached', $user, ['purpose' => $purpose->value, 'window' => 'day']);
            }

            $this->throttled($result['retry']);
        }

        if (! $this->deliver($user, $purpose, $channel, $destination, $code)) {
            // Mã chưa tới người dùng: xoá hẳn để không bị tính vào trần giờ/ngày và cooldown (bảng
            // `otp_codes` là nguồn đếm), người dùng bấm gửi lại ngay được.
            OtpCode::query()->whereKey($result['id'])->delete();

            // Ném TẠI ĐÂY (frame `issue()` không có đối số chứa mã) — xem ghi chú ở deliver().
            throw new DomainException(
                code: 'OTP_DELIVERY_FAILED',
                message: 'Hiện chưa gửi được mã xác thực. Vui lòng thử lại sau ít phút.',
                status: 503,
            );
        }

        return $result['at']->addSeconds($cooldown);
    }

    /**
     * Kiểm trần gửi mã TRƯỚC khi làm việc khác (vd. đổi liên hệ) để request bị chặn không để lại
     * thay đổi dở dang. Chỉ là kiểm sớm — `issue()` vẫn kiểm lại dưới khoá hàng.
     *
     * @throws ThrottleRequestsException
     */
    public function assertCanSend(User $user, OtpPurpose $purpose, bool $enforceCooldown = true): void
    {
        $blocked = $this->sendLimitViolation($user, $enforceCooldown, (int) config('auth.otp.cooldown_seconds'));

        if ($blocked !== null) {
            if ($blocked['audit']) {
                $this->audit->log('otp.send_limit_reached', $user, ['purpose' => $purpose->value, 'window' => 'day']);
            }

            $this->throttled($blocked['retry']);
        }
    }

    /**
     * Xác thực tài khoản bằng mã. Thành công → ghi `email_verified_at`/`phone_verified_at`.
     *
     * @throws ValidationException mã sai / hết hạn (field `code`)
     * @throws DomainException TOO_MANY_ATTEMPTS (429) khi mã đã hết lượt
     */
    public function verifyAccount(User $user, #[\SensitiveParameter] string $code): User
    {
        // Mã chỉ có giá trị cho đúng đích nó được gửi tới (S9). Kiểm 2 lần: trước khi tăng attempts
        // (loại sớm) và lại TRONG transaction dưới khoá hàng user (chống race với đổi liên hệ).
        // S3: thư phụ huynh chỉ gửi ở lần xác thực ĐẦU TIÊN trong đời tài khoản. Đổi email rồi xác thực lại không gửi thêm
        // (đã từng có dòng audit `account.verified`).
        $firstVerification = ! $user->isVerified() && ! AuditLog::query()
            ->where('action', 'account.verified')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->exists();

        $destinationValid = static function (OtpCode $otp, User $u): bool {
            $current = $otp->channel === 'sms' ? $u->phone : $u->email;

            return $current !== null && hash_equals($otp->destination, $current);
        };

        $otp = $this->consume(
            $user,
            OtpPurpose::VerifyAccount,
            $code,
            $destinationValid,
            function (OtpCode $otp, User $locked): void {
                $column = $otp->channel === 'sms' ? 'phone_verified_at' : 'email_verified_at';
                $locked->forceFill([$column => now()])->save();
            },
        );

        $user->refresh();
        $this->audit->log('account.verified', $user, ['channel' => $otp->channel]);

        // ADR-006 (R1 review): thư "tài khoản mới" cho phụ huynh chỉ gửi SAU lần xác thực OTP đầu tiên, để tài khoản
        // chưa chứng minh được email không dùng hệ thống làm relay thư tới bên thứ ba. Notifier tự nuốt lỗi.
        if ($firstVerification) {
            $this->parentNotifier->accountCreated($user);
        }

        return $user;
    }

    /**
     * Lõi so mã dùng chung cho mọi purpose (T27/T28/T29 tái sử dụng).
     * Thứ tự: tìm mã → TĂNG attempts nguyên tử (ngoài transaction: rollback sẽ làm mất bảo vệ
     * brute-force) → so hash → transaction { khoá user, kiểm lại đích, consume bằng UPDATE có điều
     * kiện, chạy `$apply` }. Chỉ request nào tăng được `attempts` mới được so.
     *
     * @param  (callable(OtpCode, User): bool)|null  $destinationStillValid
     * @param  (callable(OtpCode, User): void)|null  $apply  chạy trong cùng transaction với consume
     */
    public function consume(User $user, OtpPurpose $purpose, #[\SensitiveParameter] string $code, ?callable $destinationStillValid = null, ?callable $apply = null): OtpCode
    {
        $max = (int) config('auth.otp.max_attempts_per_code');

        $otp = OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->orderByDesc('id')
            ->first();

        if ($otp === null && $purpose === OtpPurpose::VerifyAccount && $user->isVerified()) {
            throw ValidationException::withMessages(['code' => self::MESSAGE_ALREADY_VERIFIED]);
        }

        if ($otp === null || $otp->expires_at->isPast()) {
            throw OtpValidationException::expired(self::MESSAGE_EXPIRED);
        }

        if ($destinationStillValid !== null && ! $destinationStillValid($otp, $user)) {
            throw OtpValidationException::expired(self::MESSAGE_EXPIRED);
        }

        // S9: tăng TRƯỚC khi so, trong 1 câu UPDATE có điều kiện. 0 dòng = hết lượt/hết hạn/đã bị
        // request khác dùng — tuyệt đối không được so mã trong trường hợp này.
        $counted = OtpCode::query()
            ->whereKey($otp->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', $max)
            ->increment('attempts');

        if ($counted === 0) {
            $this->throwExhaustedOrExpired($otp, $max);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            throw OtpValidationException::invalid(self::MESSAGE_WRONG);
        }

        DB::transaction(function () use ($user, $otp, $destinationStillValid, $apply): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($destinationStillValid !== null && ! $destinationStillValid($otp, $locked)) {
                throw OtpValidationException::expired(self::MESSAGE_EXPIRED);
            }

            // Consume: đúng 1 request thắng (UPDATE có điều kiện), các request song song còn lại thua.
            $consumed = OtpCode::query()
                ->whereKey($otp->getKey())
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['consumed_at' => now()]);

            if ($consumed !== 1) {
                throw OtpValidationException::invalid(self::MESSAGE_WRONG);
            }

            if ($apply !== null) {
                $apply($otp, $locked);
            }
        });

        return $otp;
    }

    /**
     * Huỷ mã còn hiệu lực của user theo kênh (đổi email → `email`, đổi SĐT → `sms`; S9).
     * Không đụng mã của kênh có đích không đổi.
     *
     * @param  list<string>  $channels
     */
    public function invalidateForChannels(User $user, array $channels): void
    {
        OtpCode::query()
            ->where('user_id', $user->getKey())
            ->whereIn('channel', $channels)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    private function throwExhaustedOrExpired(OtpCode $otp, int $max): never
    {
        $fresh = OtpCode::query()->find($otp->getKey());

        if ($fresh !== null && $fresh->consumed_at === null && $fresh->invalidated_at === null
            && $fresh->expires_at->isFuture() && $fresh->attempts >= $max) {
            throw new DomainException(
                code: 'TOO_MANY_ATTEMPTS',
                message: 'Bạn đã nhập sai quá nhiều lần. Hãy bấm "Gửi lại mã" để nhận mã mới.',
                status: 429,
            );
        }

        throw OtpValidationException::expired(self::MESSAGE_EXPIRED);
    }

    /**
     * Gọi TRONG transaction đã khoá hàng user. Trả `null` nếu được phép gửi.
     *
     * @return array{retry: int, audit: bool}|null
     */
    private function sendLimitViolation(User $user, bool $enforceCooldown, int $cooldown): ?array
    {
        $now = CarbonImmutable::now();

        $base = fn () => OtpCode::query()->where('user_id', $user->getKey());

        if ($enforceCooldown) {
            $last = $base()->max('created_at');

            if ($last !== null) {
                $retryAt = CarbonImmutable::parse($last)->addSeconds($cooldown);

                if ($retryAt->isFuture()) {
                    return ['retry' => (int) ceil($now->diffInSeconds($retryAt, true)), 'audit' => false];
                }
            }
        }

        $limits = [
            [(int) config('auth.otp.max_per_hour'), $now->subHour(), '1 hour', false],
            [(int) config('auth.otp.max_per_day'), $now->subDay(), '1 day', true],
        ];

        foreach ($limits as [$max, $since, $interval, $audit]) {
            $createdAts = $base()->where('created_at', '>', $since)->orderBy('created_at')->pluck('created_at');

            if ($createdAts->count() >= $max) {
                // Mã cũ nhất còn tính trong cửa sổ hết hạn thì được gửi tiếp.
                $oldestCounted = CarbonImmutable::parse($createdAts[$createdAts->count() - $max]);

                return [
                    'retry' => max(1, (int) ceil($now->diffInSeconds($oldestCounted->add($interval), true))),
                    'audit' => $audit,
                ];
            }
        }

        return null;
    }

    /**
     * Gửi mã. Lỗi từ nhà cung cấp KHÔNG được rò mã ra log: stack trace chứa đối số `$code`
     * (zend.exception_ignore_args=Off ở CLI/dev), nên exception gốc bị nuốt (chỉ ghi tên class)
     * và exception mới được ném ở frame không mang mã (S9/S21).
     *
     * @return bool `false` nếu gửi thất bại
     */
    private function deliver(User $user, OtpPurpose $purpose, string $channel, string $destination, #[\SensitiveParameter] string $code): bool
    {
        try {
            $this->sender->send($user, $purpose, $channel, $destination, $code);

            return true;
        } catch (Throwable $e) {
            // Giữ nguyên nhân thật nhưng che mọi chuỗi 6 số; KHÔNG log trace (có đối số chứa mã).
            // Mức error (T27-3): mã không tới người dùng là sự cố vận hành cần cảnh báo, kể cả khi luồng
            // gọi (forgot) cố ý không báo lỗi ra response.
            Log::error('Gửi OTP thất bại.', [
                'channel' => $channel,
                'exception' => $e::class,
                'reason' => mb_substr((string) preg_replace(['/\d{6}/', '/[^\s<>"\']+@[^\s<>"\']+/'], ['******', '***@***'], $e->getMessage()), 0, 300),
            ]);

            return false;
        }
    }

    private function throttled(int $retryAfter): never
    {
        throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
