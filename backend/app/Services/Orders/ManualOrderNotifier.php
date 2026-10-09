<?php

namespace App\Services\Orders;

use App\Mail\ManualOrderCancelledMail;
use App\Mail\ManualOrderReceivedMail;
use App\Mail\NewManualOrderStaffMail;
use App\Models\Order;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Thư của luồng thanh toán thủ công (US-022, ADR-007 §10). Gọi TRONG `DB::afterCommit` của người gọi (thư chỉ vào queue khi
 * transaction đã commit; rollback/retry deadlock thì không gửi). Mọi lỗi gửi được nuốt + log: không làm hỏng thao tác chính.
 *
 * - Học sinh chỉ nhận thư khi có email ĐÃ XÁC THỰC và tài khoản chưa ẩn danh.
 * - Thư hộp thư quản trị KHÔNG chứa PII (tên/email/SĐT/ghi chú của học sinh).
 */
class ManualOrderNotifier
{
    /**
     * @param  list<string>  $courseTitles
     */
    public function received(Order $order, User $student, array $courseTitles): void
    {
        $this->safely('received', $order, function () use ($order, $student, $courseTitles): void {
            if (! $this->canMailStudent($student)) {
                return;
            }

            Mail::to((string) $student->email)->queue(new ManualOrderReceivedMail(
                studentName: (string) $student->name,
                orderCode: $order->code,
                courseTitles: $courseTitles,
                total: $order->total_amount,
                expiresAtText: $this->localTime($order->expires_at),
                contact: $this->contact(),
                orderUrl: $this->studentOrderUrl($order),
            ));
        });
    }

    public function newOrderForStaff(Order $order, int $courseCount): void
    {
        $this->safely('staff', $order, function () use ($order, $courseCount): void {
            $recipients = array_values(array_filter((array) config('orders.manual.notify_emails', []), 'is_string'));

            if ($recipients === []) {
                Log::warning('manual_order.staff_mail_skipped_no_recipient', ['order' => $order->code]);

                return;
            }

            Mail::to($recipients)->queue(new NewManualOrderStaffMail(
                orderCode: $order->code,
                total: $order->total_amount,
                courseCount: $courseCount,
                createdAtText: $this->localTime($order->created_at),
                adminUrl: rtrim((string) config('app.admin_url'), '/').'/quan-tri/don-hang/'.$order->code,
            ));
        });
    }

    /** @param  'expired'|'admin_cancelled'  $variant */
    public function cancelled(Order $order, User $student, string $variant, ?string $publicReason = null): void
    {
        $this->safely('cancelled', $order, function () use ($order, $student, $variant, $publicReason): void {
            if (! $this->canMailStudent($student)) {
                return;
            }

            Mail::to((string) $student->email)->queue(new ManualOrderCancelledMail(
                variant: $variant,
                studentName: (string) $student->name,
                orderCode: $order->code,
                publicReason: $publicReason,
                contact: $this->contact(),
                orderUrl: $this->studentOrderUrl($order),
            ));
        });
    }

    private function canMailStudent(User $student): bool
    {
        return $student->anonymized_at === null
            && is_string($student->email) && $student->email !== ''
            && $student->email_verified_at !== null;
    }

    /** @return array{phone: ?string, zalo_url: ?string, email: ?string, hours: ?string} */
    private function contact(): array
    {
        $contact = (array) config('orders.manual.contact', []);
        $pick = fn (string $key): ?string => is_string($contact[$key] ?? null) && trim((string) $contact[$key]) !== '' ? trim((string) $contact[$key]) : null;

        return ['phone' => $pick('phone'), 'zalo_url' => $pick('zalo_url'), 'email' => $pick('email'), 'hours' => $pick('hours')];
    }

    private function studentOrderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/thanh-toan/da-gui/'.$order->code;
    }

    private function localTime(?DateTimeInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        return Carbon::instance($at)->setTimezone((string) config('privacy.age_timezone', 'Asia/Ho_Chi_Minh'))->format('H:i d/m/Y');
    }

    private function safely(string $what, Order $order, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('Không gửi được thư đơn thủ công.', ['what' => $what, 'order' => $order->code, 'exception' => $e::class]);
        }
    }
}
