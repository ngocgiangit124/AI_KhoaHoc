<?php

namespace App\Mail;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email chứa mã OTP rõ. ShouldBeEncrypted: payload trong queue/`failed_jobs`
 * là ciphertext, không lộ mã/tên/email (S21).
 */
class OtpMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mã xác thực VitaminVui của bạn');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
