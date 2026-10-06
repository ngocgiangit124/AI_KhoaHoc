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
 * Gửi tới email CŨ (đã xác thực) khi email tài khoản bị đổi (H1 review bảo mật cụm 1). Chỉ chứa email mới dạng che
 *
 * (`a***@example.com`), thời điểm đổi và cách liên hệ hỗ trợ; không có mã hay liên kết thao tác nào.
 */
class ContactChangedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $recipientName,
        public readonly string $maskedNewEmail,
        public readonly string $changedAt,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Email tài khoản VitaminVui vừa được thay đổi');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-changed');
    }
}
