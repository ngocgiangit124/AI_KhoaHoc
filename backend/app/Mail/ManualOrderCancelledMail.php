<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Đơn bị huỷ" gửi học sinh: biến thể `expired` (hết hạn chờ, T38) và `admin_cancelled` (Quản trị viên huỷ kèm lý do công khai,
 * T39). Học sinh tự huỷ thì KHÔNG gửi thư.
 */
class ManualOrderCancelledMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public const VARIANT_EXPIRED = 'expired';

    public const VARIANT_ADMIN_CANCELLED = 'admin_cancelled';

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array{phone: ?string, zalo_url: ?string, email: ?string, hours: ?string}  $contact
     */
    public function __construct(
        public readonly string $variant,
        public readonly string $studentName,
        public readonly string $orderCode,
        public readonly ?string $publicReason,
        public readonly array $contact,
        public readonly string $orderUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->variant === self::VARIANT_EXPIRED
            ? 'Đơn #'.$this->orderCode.' đã hết hạn chờ'
            : 'Đơn #'.$this->orderCode.' đã bị huỷ');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.manual-order-cancelled');
    }
}
