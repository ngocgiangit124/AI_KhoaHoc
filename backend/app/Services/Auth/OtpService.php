<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpSenderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * Sinh mã mới cho (user, purpose, channel), huỷ mọi mã còn hiệu lực của
     * ĐÚNG bộ 3 này (data-model §3.1 — "invalidated_at: khi phát mã mới cùng
     * purpose/kênh"), rồi gửi qua `OtpSender` tương ứng.
     *
     * @return Carbon Thời điểm được phép bấm "Gửi lại mã" (`resend_available_at`).
     */
    public function send(User $user, OtpPurpose $purpose, string $channel): Carbon
    {
        $this->assertChannelAllowed($channel);

        $destination = self::destinationFor($user, $channel);
        $code = self::generateCode();

        DB::transaction(function () use ($user, $purpose, $channel, $destination, $code): void {
            OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose->value)
                ->where('channel', $channel)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            OtpCode::query()->create([
                'user_id' => $user->getKey(),
                'purpose' => $purpose->value,
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('auth.otp.ttl_minutes')),
            ]);
        });

        $this->senders->forChannel($channel)->send($destination, $code);

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

        // S9 — tăng attempts NGUYÊN TỬ bằng UPDATE có điều kiện TRƯỚC khi so
        // mã: 0 dòng ảnh hưởng (hết hạn/đã tiêu thụ/hết lượt) = coi như sai.
        $affected = OtpCode::query()
            ->where('id', $otp->getKey())
            ->whereNull('consumed_at')
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
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            // Race hiếm: 1 request khác đã tiêu thụ đúng mã này giữa lúc so
            // hash và lúc consume (vd 2 tab gửi cùng mã đúng gần như đồng thời).
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
