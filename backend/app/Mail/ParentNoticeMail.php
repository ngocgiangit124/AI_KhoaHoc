<?php

namespace App\Mail;

use App\Support\Mask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thư THÔNG BÁO gửi cho phụ huynh (HTML + text thuần, KHÔNG markdown: mọi chuỗi động đi qua `e()`) (ADR-006, api-contract §2.8.2). Phụ huynh không phải làm gì.
 *
 * Gửi tới bên thứ ba nên: payload mã hoá trong queue (`ShouldBeEncrypted`), chỉ có tên học sinh đã che
 * (không email/SĐT/ngày sinh học sinh), có link huỷ nhận + header `List-Unsubscribe` one-click (RFC 8058).
 */
class ParentNoticeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public const KIND_ACCOUNT_CREATED = 'account_created';

    public const KIND_CONTACT_ADDED = 'parent_contact_added';

    public const KIND_ORDER_PAID = 'order_paid';

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<string>  $courseTitles  chỉ dùng cho `order_paid`
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $maskedStudentName,
        public readonly string $eventAt,
        public readonly string $policyVersion,
        public readonly string $policyUrl,
        public readonly string $supportEmail,
        public readonly string $unsubscribeUrl,
        public readonly string $oneClickUrl,
        public readonly ?string $orderCode = null,
        public readonly array $courseTitles = [],
        public readonly ?int $totalAmount = null,
    ) {
        $this->afterCommit();
    }

    /**
     * T29-S4: lỗi transport SMTP thường kèm địa chỉ nhận trong thông điệp. Bắt mọi lỗi lúc gửi và ném lại một exception MỚI
     * (không `previous`) với thông điệp đã che email, để `failed_jobs.exception` và log của worker không chứa địa chỉ phụ huynh.
     * Vẫn là lỗi nên cơ chế retry/backoff giữ nguyên.
     */
    public function send($mailer)
    {
        try {
            return parent::send($mailer);
        } catch (Throwable $e) {
            throw new RuntimeException(class_basename($e).': '.Mask::emailsInText($e->getMessage()));
        }
    }

    /** Gọi khi hết lượt thử; `$e` đã được che ở `send()`, che lại một lần nữa phòng lỗi phát sinh ngoài `send()`. */
    public function failed(Throwable $e): void
    {
        Log::warning('parent_notice.mail_failed', ['kind' => $this->kind, 'error' => Mask::emailsInText($e->getMessage())]);
    }

    public function envelope(): Envelope
    {
        // S1: tiêu đề CỐ ĐỊNH, không chứa dữ liệu người dùng nhập (tên học sinh chỉ có trong thân thư).
        return new Envelope(subject: match ($this->kind) {
            self::KIND_ORDER_PAID => 'VitaminVui: đơn hàng của học sinh đã được thanh toán',
            self::KIND_CONTACT_ADDED => 'VitaminVui: địa chỉ này được ghi là liên hệ phụ huynh',
            default => 'VitaminVui: thông báo về tài khoản học của học sinh',
        });
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->oneClickUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.parent-notice', text: 'emails.parent-notice-text');
    }
}
