<?php

namespace App\Services\Privacy;

use App\Mail\ParentNoticeMail;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Thư THÔNG BÁO cho phụ huynh (ADR-006, api-contract §2.8.2). Không bao giờ làm hỏng request gốc: mọi lỗi bị nuốt
 * (báo qua `report()`, không log email/SĐT).
 *
 * Gọi SAU khi transaction của nghiệp vụ gốc đã commit (người gọi dùng `DB::afterCommit`), vì thư không được tới
 * phụ huynh nếu thao tác gốc bị rollback.
 */
class ParentNotifier
{
    private const CAP_WINDOW_SECONDS = 86400;

    private const GLOBAL_CAP_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ParentNoticeSuppression $suppression,
    ) {}

    /**
     * Gọi SAU khi học sinh xác thực OTP lần đầu (không gọi lúc đăng ký).
     */
    public function accountCreated(User $user): void
    {
        $this->send($user, ParentNoticeMail::KIND_ACCOUNT_CREATED, $user->created_at?->toImmutable());
    }

    public function contactAdded(User $user): void
    {
        // Tài khoản chưa xác thực chưa gửi thư nào cho bên thứ ba (chống relay); khi xác thực lần đầu sẽ gửi `account_created`.
        if (! $user->isVerified()) {
            return;
        }

        $this->send($user, ParentNoticeMail::KIND_CONTACT_ADDED, now());
    }

    /**
     * Chỉ đơn có tiền (`total_amount > 0`). Đơn 0đ/học miễn phí không gửi.
     */
    public function orderPaid(Order $order): void
    {
        // Chạy trong afterCommit của luồng thanh toán: MỌI lỗi (kể cả truy vấn DB ở đây) bị nuốt để IPN/markPaid không 500.
        try {
            if ($order->total_amount <= 0) {
                return;
            }

            $user = User::query()->find($order->user_id);

            if ($user === null) {
                return;
            }

            // Tên khoá lấy từ bảng `courses` (staff nhập), dự phòng bản chụp lúc đặt đơn khi khoá đã bị xoá cứng.
            $titles = DB::table('order_items')
                ->leftJoin('courses', 'courses.id', '=', 'order_items.course_id')
                ->where('order_items.order_id', $order->getKey())
                ->orderBy('order_items.id')
                ->selectRaw('COALESCE(courses.title, order_items.course_title) AS title')
                ->pluck('title')
                ->map(fn ($t) => self::displayText((string) $t, 'Khoá học'))->all();

            $this->send($user, ParentNoticeMail::KIND_ORDER_PAID, $order->paid_at ?? now(), [
                'orderCode' => $order->code,
                'courseTitles' => $titles,
                'totalAmount' => (int) $order->total_amount,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Làm sạch chuỗi do người dùng/nhân viên nhập trước khi đưa vào thư gửi bên thứ ba: chỉ giữ chữ (mọi ngôn ngữ), số, khoảng
     * trắng và vài dấu câu an toàn. Bỏ `[ ] ( ) < > : / . @ _ \` ` # ! |` nên không thể tạo link markdown/HTML và cũng không còn
     * chuỗi dạng tên miền/URL để trình đọc thư tự biến thành liên kết.
     */
    public static function displayText(string $text, string $fallback = '***'): string
    {
        $clean = preg_replace('/[^\p{L}\p{M}\p{N} \-,\'&+]+/u', '', $text) ?? '';
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $clean = mb_substr($clean, 0, 120);

        return $clean === '' ? $fallback : $clean;
    }

    /**
     * `Nguyễn Minh Anh` → `Nguyễn Minh A**`: giữ mọi từ trừ từ cuối; từ cuối giữ ký tự đầu + `**`.
     */
    public static function maskName(string $name): string
    {
        // S1 (security T29): tên là chuỗi tự do của học sinh nên chỉ để lộ tối thiểu: không chữ số, tối đa 4 từ, mỗi từ ≤ 15 ký tự.
        $letters = preg_replace('/[\p{N}]+/u', '', self::displayText($name, '')) ?? '';
        $words = array_map(
            fn (string $w) => mb_substr($w, 0, 15),
            array_slice(preg_split('/\s+/u', trim($letters), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4),
        );

        if ($words === []) {
            return '***';
        }

        $last = array_pop($words);
        $words[] = mb_substr($last, 0, 1).'**';

        return implode(' ', $words);
    }

    /**
     * @param  array{orderCode?: string, courseTitles?: list<string>, totalAmount?: int}  $extra
     */
    private function send(User $user, string $kind, ?\DateTimeInterface $eventAt, array $extra = []): void
    {
        try {
            $this->deliver($user, $kind, $eventAt, $extra);
        } catch (Throwable $e) {
            // Không log địa chỉ: report() chỉ ghi exception; thư không gửi được thì request gốc vẫn thành công.
            report($e);
        }
    }

    /**
     * @param  array{orderCode?: string, courseTitles?: list<string>, totalAmount?: int}  $extra
     */
    private function deliver(User $user, string $kind, ?\DateTimeInterface $eventAt, array $extra): void
    {
        if (! config('features.parent_notices')) {
            return;
        }

        $email = $user->parent_email;

        if ($user->anonymized_at !== null || ! is_string($email) || $email === '' || $user->parent_notice_opt_out_at !== null) {
            return;
        }

        $email = mb_strtolower($email);

        // Địa chỉ đã được phụ huynh huỷ nhận (danh sách chặn theo HMAC) thì không bao giờ gửi, dù học sinh đổi/xoá/thêm lại.
        if ($this->suppression->isSuppressed($email)) {
            return;
        }

        $cap = (int) config('privacy.parent_notice_daily_cap_per_address');

        // S2: trần không hợp lệ (≤ 0 hoặc > 20) thì FAIL-CLOSED, không gửi (cấu hình sai không được biến thành "không giới hạn").
        if ($cap < 1 || $cap > 20) {
            Log::warning('parent_notice.invalid_cap', ['kind' => $kind]);

            return;
        }

        // T29-S6 (R1): trần TỔNG mọi thư gửi bên thứ ba mỗi giờ (chống spam hàng loạt qua nhiều tài khoản). Kiểm TRƯỚC bộ đếm theo
        // địa chỉ để thư bị trần tổng chặn không tốn lượt 5/ngày của phụ huynh thật. Fail-closed khi cấu hình < 1.
        $globalCap = (int) config('privacy.parent_notice_global_hourly_cap');

        if ($globalCap < 1 || RateLimiter::tooManyAttempts('parent-notice:global', $globalCap)) {
            $this->globalCapReached($kind, $globalCap);

            return;
        }

        // Trần theo ĐỊA CHỈ nhận, tính trên mọi học sinh (khoá chỉ chứa hash). `hit()` tăng nguyên tử rồi mới so sánh.
        $addressKey = 'parent-notice:'.ParentNoticeToken::addressHash($email);

        if (RateLimiter::hit($addressKey, self::CAP_WINDOW_SECONDS) > $cap) {
            Log::warning('parent_notice.daily_cap_reached', ['kind' => $kind, 'user_id' => $user->getKey()]);

            return;
        }

        $token = ParentNoticeToken::make($user);

        if ($token === null) {
            return;
        }

        // Chỉ thư thật sự sắp gửi mới tính vào trần tổng. `hit()` nguyên tử: nếu nhiều tiến trình cùng vượt trần sau lần kiểm
        // ở trên thì thư vượt bị bỏ và hoàn lại lượt của địa chỉ.
        if (RateLimiter::hit('parent-notice:global', self::GLOBAL_CAP_WINDOW_SECONDS) > $globalCap) {
            RateLimiter::decrement($addressKey);
            $this->globalCapReached($kind, $globalCap);

            return;
        }

        $timezone = (string) config('privacy.age_timezone');
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        Mail::to($email)->queue(new ParentNoticeMail(
            kind: $kind,
            maskedStudentName: self::maskName((string) $user->name),
            eventAt: CarbonImmutable::instance($eventAt ?? now())->setTimezone($timezone)->format('H:i d/m/Y'),
            policyVersion: (string) config('privacy.policy_version'),
            policyUrl: $frontend.'/chinh-sach-du-lieu',
            supportEmail: (string) config('ops.support_email'),
            unsubscribeUrl: $frontend.'/phu-huynh/huy-nhan-thong-bao?t='.$token,
            oneClickUrl: $scheme.'://'.config('app.api_host').'/api/v1/parent-notices/unsubscribe?t='.$token,
            orderCode: $extra['orderCode'] ?? null,
            courseTitles: $extra['courseTitles'] ?? [],
            totalAmount: $extra['totalAmount'] ?? null,
        ));

        $this->audit->logAsSystem('parent_notice.sent', $user, ['kind' => $kind]);
    }

    private function globalCapReached(string $kind, int $cap): void
    {
        Log::warning('parent_notice.global_cap_reached', ['kind' => $kind, 'cap' => $cap]);
    }
}
