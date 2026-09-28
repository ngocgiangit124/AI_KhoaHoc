<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
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
     *                         (cooldown/giờ/ngày — S9, T04 review R1).
     */
    public function send(User $user, OtpPurpose $purpose, string $channel): Carbon
    {
        $this->assertChannelAllowed($channel);
        $this->assertUnderSendLimits($user);

        $destination = self::destinationFor($user, $channel);
        $code = self::generateCode();

        DB::transaction(function () use ($user, $purpose, $destination, $channel, $code): void {
            OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose->value)
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
     * T04 review R1 — cho phép caller (vd `ContactService`) kiểm trần gửi
     * TRƯỚC khi thực hiện thay đổi khác (fail-closed: không đổi email/SĐT
     * nếu biết chắc sẽ không gửi được OTP xác thực cho giá trị mới), tránh
     * trạng thái nửa vời "đã đổi liên hệ nhưng không có cách xác thực".
     * Không làm gì (không ném lỗi) nếu kênh chưa được bật — khớp hành vi của
     * `sendIfChannelEnabled()` (không có gì để giới hạn nếu sẽ không gửi).
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

    /**
     * T04 review R1 [BLOCKER] — TRƯỚC ĐÂY trần gửi (cooldown 60s, ≤5/giờ,
     * ≤10/ngày — S9, api-contract §1.6) CHỈ được `throttle:otp-send` áp ở
     * tầng route `POST /auth/otp/send`. `PUT /auth/contact` gọi thẳng
     * `send()` qua `ContactService`/`sendIfChannelEnabled()` mà KHÔNG đi qua
     * route đó, nên không bị giới hạn gì — một tài khoản có thể đổi qua đổi
     * lại email để gửi OTP thật liên tục tới bất kỳ hộp thư nào (email
     * bombing). Đưa trần vào NGAY TRONG Service (đếm số `otp_codes` thật đã
     * tạo cho user trong DB — nguồn sự thật độc lập với route/middleware nào
     * gọi tới) để MỌI caller hiện tại (otp/send, register, contact) và
     * tương lai (T27, T29...) đều tự động bị chặn, không cần mỗi route tự
     * nhớ gắn đúng middleware. Đây là lớp phòng thủ thứ 2, độc lập với
     * `throttle:otp-send` (lớp 1, vẫn giữ trên cả 2 route — xem routes/api.php).
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

        if ($latestOtp !== null && $latestOtp->created_at->copy()->addSeconds($cooldownSeconds)->isFuture()) {
            throw self::tooManySendException();
        }

        $maxPerHour = (int) config('auth.otp.max_per_hour');
        $sentLastHour = OtpCode::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $now->copy()->subHour())
            ->count();

        if ($sentLastHour >= $maxPerHour) {
            throw self::tooManySendException();
        }

        $maxPerDay = (int) config('auth.otp.max_per_day');
        $sentLastDay = OtpCode::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();

        if ($sentLastDay >= $maxPerDay) {
            throw self::tooManySendException();
        }
    }

    private static function tooManySendException(): DomainException
    {
        return new DomainException(
            code: 'TOO_MANY_ATTEMPTS',
            message: 'Bạn gửi mã quá nhanh, vui lòng thử lại sau.',
            status: 429,
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
