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
 * Thông báo hộp thư Quản trị viên có đơn thủ công mới (US-022 BR20). KHÔNG chứa PII: chỉ mã đơn, tổng tiền, số khóa, thời điểm
 * và link vào trang quản trị. Không có tên/email/SĐT/ghi chú của học sinh.
 */
class NewManualOrderStaffMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $orderCode,
        public readonly int $total,
        public readonly int $courseCount,
        public readonly string $createdAtText,
        public readonly string $adminUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đơn thanh toán thủ công mới #'.$this->orderCode);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-manual-order-staff');
    }
}
