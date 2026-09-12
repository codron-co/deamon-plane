<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class PlatformOpsMail extends Mailable
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $mailSubject,
        public readonly string $body,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform-ops');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
        ]);
    }
}
