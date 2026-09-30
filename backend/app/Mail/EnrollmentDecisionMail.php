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
 * Thông báo kết quả duyệt/từ chối đăng ký khóa học miễn phí (US-012 AC2/AC3,
 * api-contract §3 `Mail/`). Chỉ gửi khi `features.enrollment_decision_mail`
 * bật (mặc định TẮT — chờ PO, README §3.3) — xem
 * `EnrollmentService::sendDecisionMailIfEnabled()`. `ShouldBeEncrypted` (S21
 * — payload job chứa tên học sinh + tên khóa học, không nằm dạng rõ trong
 * `jobs`/`failed_jobs`).
 */
class EnrollmentDecisionMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $courseTitle,
        public readonly bool $approved,
        public readonly ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved
                ? 'Yêu cầu đăng ký khóa học đã được duyệt — VitaminVui'
                : 'Yêu cầu đăng ký khóa học chưa được duyệt — VitaminVui',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.enrollment-decision',
            with: [
                'recipientName' => $this->recipientName,
                'courseTitle' => $this->courseTitle,
                'approved' => $this->approved,
                'reason' => $this->reason,
            ],
        );
    }
}
