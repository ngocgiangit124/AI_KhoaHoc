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
 * "Đã nhận đơn" gửi học sinh khi đặt đơn thanh toán thủ công (US-022 AC3). Chỉ đẩy vào queue SAU commit.
 * `ShouldBeEncrypted`: tên và email học sinh không nằm rõ trong Redis/`failed_jobs`.
 */
class ManualOrderReceivedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<string>  $courseTitles
     * @param  array{phone: ?string, zalo_url: ?string, email: ?string, hours: ?string}  $contact
     */
    public function __construct(
        public readonly string $studentName,
        public readonly string $orderCode,
        public readonly array $courseTitles,
        public readonly int $total,
        public readonly string $expiresAtText,
        public readonly array $contact,
        public readonly string $orderUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đã nhận đơn #'.$this->orderCode.' - chờ Quản trị viên liên hệ');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.manual-order-received');
    }
}
