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
 * Cảnh báo giáo viên đăng nhập quản trị từ thiết bị mới (S15, US-016 AC10). Không chặn đăng nhập.
 * ShouldBeEncrypted: payload queue chứa IP/User-Agent nên mã hoá (S21).
 */
class StaffNewDeviceMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** Gửi mail quá 30s là treo SMTP: huỷ để thử lại theo backoff (nhỏ hơn retry_after 90s). */
    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $recipientName,
        public readonly string $loggedInAt,
        public readonly ?string $ip,
        public readonly string $userAgent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đăng nhập quản trị VitaminVui từ thiết bị mới');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-new-device');
    }
}
