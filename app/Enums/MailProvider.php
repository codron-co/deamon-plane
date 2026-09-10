<?php

namespace App\Enums;

enum MailProvider: string
{
    case Hostinger = 'hostinger';
    case Mailcow = 'mailcow';

    public function isImplemented(): bool
    {
        return $this === self::Hostinger;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hostinger => (string) __('mail.providers.hostinger'),
            self::Mailcow => (string) __('mail.providers.mailcow'),
        };
    }
}
