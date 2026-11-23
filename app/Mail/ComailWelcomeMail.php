<?php

namespace App\Mail;

use App\Services\Comail\ComailWelcome;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "E-posta hesabınız hazır" mail for a new CoMail mailbox (App\Services\Comail\ComailWelcome).
 * HTML in the Deamon mail family (table layout, inline CSS, dark mode) plus a plain-text part.
 */
final class ComailWelcomeMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $welcome  ComailWelcome::data()
     */
    public function __construct(
        public readonly array $welcome,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: [new Address(ComailWelcome::SUPPORT_EMAIL, 'CodRon Destek')],
            subject: ComailWelcome::subject((string) $this->welcome['address']),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.comail-welcome.html',
            text: 'mail.comail-welcome.text',
            with: ['w' => $this->welcome],
        );
    }
}
