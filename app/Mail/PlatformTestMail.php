<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PlatformTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('platform_mail.test.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform-test');
    }
}
