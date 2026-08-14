<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Намеренно НЕ ShouldQueue: в проекте QUEUE_CONNECTION=database, но воркер
 * никогда не запускается (тот же принцип, что и в App\Notifications\SpaceAccessGranted)
 * — письмо с кодом отправляется синхронно, иначе оно просто зависнет в очереди.
 */
class LoginVerificationCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Код входа в Nodus',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-verification-code',
        );
    }
}
