<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PlatformTestMail extends Mailable
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: __('platform_mail.test.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform-test');
    }
}
