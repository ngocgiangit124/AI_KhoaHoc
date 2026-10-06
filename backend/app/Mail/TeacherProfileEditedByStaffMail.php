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
 * Báo giáo viên đang đồng ý công khai rằng Admin/QLT vừa sửa hộ hồ sơ công khai của họ (US-020 BR6, security T36). Chỉ nêu
 * TÊN trường đã đổi, KHÔNG chứa toàn văn bio/headline. ShouldBeEncrypted: payload queue có tên + email người nhận (S21).
 */
class TeacherProfileEditedByStaffMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<string>  $fieldLabels  tên trường tiếng Việt (dòng chuyên môn, phần giới thiệu, ảnh đại diện)
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $editorName,
        public readonly string $editedAt,
        public readonly array $fieldLabels,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Hồ sơ công khai của bạn trên VitaminVui vừa được quản trị viên chỉnh sửa');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.teacher-profile-edited');
    }
}
