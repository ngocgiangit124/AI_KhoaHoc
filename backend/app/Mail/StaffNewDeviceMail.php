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
 * Cảnh báo giáo viên khi tài khoản đăng nhập từ thiết bị mới (README §3.2,
 * api-contract §2.5, tasks.md T28). `ShouldQueue` (không chặn request) +
 * `ShouldBeEncrypted` (S21 — payload job chứa tên người dùng, không nằm dạng
 * rõ trong `jobs`/`failed_jobs`).
 */
class StaffNewDeviceMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Đăng nhập từ thiết bị mới — VitaminVui');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-new-device',
            with: ['recipientName' => $this->recipientName],
        );
    }
}
