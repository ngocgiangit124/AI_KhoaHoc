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
 * Xác nhận đơn đã thanh toán gửi học sinh (US-005 BR8; US-022 khi Quản trị viên duyệt). Dùng chung cho mọi nguồn có tiền
 * (`manual`, `ipn`, `query`). Chỉ đẩy vào queue SAU commit; `ShouldBeEncrypted`: tên/email không nằm rõ trong Redis/`failed_jobs`.
 */
class OrderPaidMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<string>  $courseTitles
     */
    public function __construct(
        public readonly string $studentName,
        public readonly string $orderCode,
        public readonly array $courseTitles,
        public readonly int $total,
        public readonly string $orderUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đơn #'.$this->orderCode.' đã được xác nhận thanh toán');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-paid');
    }
}
