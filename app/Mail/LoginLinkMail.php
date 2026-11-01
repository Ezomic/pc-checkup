<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LoginLinkMail extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your PC Checkup login link');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.login-link');
    }
}
