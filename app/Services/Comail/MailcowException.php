<?php

namespace App\Services\Comail;

use RuntimeException;

/**
 * A mailcow call that failed. `error` is the site API code (`provider_error` or
 * `provider_unreachable`); `providerKey` is mailcow's translation key
 * (`mailbox_quota_exceeded`, …) when it sent one. Never carries the API key.
 */
class MailcowException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 502,
        public readonly ?string $providerKey = null,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self('provider_unreachable', 'CoMail sunucusuna ulaşılamadı.', 502);
    }

    public function isUnreachable(): bool
    {
        return $this->error === 'provider_unreachable';
    }

    /**
     * Turkish message for the site API.
     */
    public function turkishMessage(): string
    {
        if ($this->isUnreachable()) {
            return 'CoMail sunucusuna şu an ulaşılamıyor. Biraz sonra tekrar deneyin.';
        }

        return ComailErrors::turkish($this->providerKey);
    }
}
