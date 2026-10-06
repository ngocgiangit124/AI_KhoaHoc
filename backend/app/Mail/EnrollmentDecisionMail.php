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
 * Báo kết quả duyệt/từ chối đăng ký học khóa miễn phí (US-012 AC2/AC3). Chạy qua queue; chỉ được đẩy vào
 * queue SAU khi transaction duyệt/từ chối commit (`afterCommit()` + `DB::afterCommit` ở EnrollmentService).
 * C4-L6: `ShouldBeEncrypted` — email, tên học sinh và lý do từ chối (giáo viên nhập tự do) không nằm rõ trong Redis/`failed_jobs`.
 */
class EnrollmentDecisionMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** Gửi mail quá 30s là treo SMTP: huỷ để thử lại theo backoff (nhỏ hơn retry_after 90s). */
    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly bool $approved,
        public readonly string $studentName,
        public readonly string $courseTitle,
        public readonly string $courseUrl,
        public readonly ?string $reason = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved
            ? 'Bạn đã được duyệt học khóa "'.$this->courseTitle.'"'
            : 'Yêu cầu học khóa "'.$this->courseTitle.'" chưa được chấp nhận');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.enrollment-decision');
    }
}
