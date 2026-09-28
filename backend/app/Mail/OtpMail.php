<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email chứa mã OTP (US-001, api-contract §2.2). `ShouldQueue` (tác vụ gửi
 * mail không chặn request — CLAUDE.md) + `ShouldBeEncrypted` (S21 — payload
 * job có mã OTP + email người nhận, không được nằm ở dạng rõ trong
 * `jobs`/`failed_jobs`).
 */
class OtpMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mã xác thực tài khoản VitaminVui');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'code' => $this->code,
                'ttlMinutes' => (int) config('auth.otp.ttl_minutes'),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
