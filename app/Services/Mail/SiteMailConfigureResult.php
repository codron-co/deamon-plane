<?php

namespace App\Services\Mail;

class SiteMailConfigureResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?int $httpStatus = null,
        public readonly bool $enabled = false,
    ) {}

    public static function ok(bool $enabled, int $httpStatus = 200): self
    {
        return new self('ok', null, $httpStatus, $enabled);
    }

    public static function needsSecret(): self
    {
        return new self('needs_secret');
    }

    public static function failure(string $message, ?int $httpStatus = null): self
    {
        return new self('failed', $message, $httpStatus);
    }

    public function flashKey(): string
    {
        return $this->status === 'failed' ? 'error' : 'status';
    }

    public function flashMessage(): string
    {
        return match ($this->status) {
            'ok' => $this->enabled
                ? (string) __('mail.flash.configured')
                : (string) __('mail.flash.disabled'),
            'needs_secret' => (string) __('mail.flash.needs_secret'),
            default => $this->message ?: (string) __('mail.flash.configure_failed'),
        };
    }
}
